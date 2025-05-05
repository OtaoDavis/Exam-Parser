<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\Exam;
use Exception;
use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory;
use Throwable;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;

class ProcessExamFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $filePath;
    protected string $originalName;
    protected string $fileExtension;
    protected ?string $imagePublicPath;

    protected const ANSWERS_DIRECTORY = 'exam_answers';

    /**
     * Create a new job instance.
     */
    public function __construct(string $filePath, string $originalName, string $fileExtension, ?string $imagePublicPath = null)
    {
        $this->filePath = $filePath;
        $this->originalName = $originalName;
        $this->fileExtension = $fileExtension;
        $this->imagePublicPath = $imagePublicPath;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("Processing job for file: {$this->originalName} ({$this->filePath})");

        $extractedText = null;
        $fullStoragePath = storage_path('app/' . $this->filePath);

        try {
            // 1. Text Extraction 
            if (in_array($this->fileExtension, ['jpg', 'jpeg', 'png'])) {
                Log::warning("OCR processing needed for image {$this->originalName} but is not implemented.");
            } elseif ($this->fileExtension === 'pdf') {
                if (!Storage::exists($this->filePath)) throw new Exception("PDF file not found at path: " . $this->filePath);
                $parser = new PdfParser(); $pdf = $parser->parseFile($fullStoragePath); $extractedText = $pdf->getText();
                Log::info("Extracted text from PDF: " . Str::limit($extractedText, 100));
            } elseif ($this->fileExtension === 'docx') {
                 if (!Storage::exists($this->filePath)) throw new Exception("DOCX file not found at path: " . $this->filePath);
                $phpWord = IOFactory::load($fullStoragePath); $text = '';
                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) { if (method_exists($element, 'getText')) $text .= $element->getText() . ' '; }
                } $extractedText = trim($text);
                Log::info("Extracted text from DOCX: " . Str::limit($extractedText, 100));
            } else { Log::error("Unsupported file type: {$this->fileExtension}"); return; }

            if (empty($extractedText) && !$this->imagePublicPath) {
                Log::error("Could not extract text: {$this->originalName}"); $this->fail("Text extraction failed"); return;
            }
             if ($this->imagePublicPath && empty($extractedText)) {
                 Log::error("Cannot generate answers for image {$this->originalName} without OCR text."); $this->fail("OCR text needed for image answers"); return;
             }

            // 2. Call Gemini API 
            $apiKey = env('GEMINI_API_KEY');
            if (!$apiKey) { Log::error('Gemini API key missing'); $this->fail('Gemini API key missing'); return; }

            $prompt = "Analyze the following exam paper content. First, extract metadata (examName, examiner, subject, class, term, year, curriculum, type).For examiner, its usually the first description in bold at the top of the paper.
            Second, *answer all questions*. Provide output strictly in JSON format (double quotes) with keys: 'examName', 'examiner', 'subject', 'class', 'term', 'year', 'curriculum', 'type', 'generatedAnswers'. Use null if metadata not found. 'generatedAnswers' should be a single string with formatted answers.\n\nExam Content:\n```\n" . substr($extractedText, 0, 25000) . "\n```\n\nJSON Output:";

            $response = Http::withHeaders(['Content-Type'=>'application/json','Accept'=>'application/json'])
                ->timeout(180)
                ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $apiKey, [ // Using 1.5 flash
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['responseMimeType' => 'application/json']
                ]);

            Log::debug("Gemini API Raw Response for {$this->originalName}: " . $response->body());
            if ($response->failed()) { Log::error("Gemini API HTTP Error {$response->status()}"); $this->fail('Gemini API failed'); return; }

            $parsedData = $response->json();
            $responseText = data_get($parsedData, 'candidates.0.content.parts.0.text');
            if (!$responseText) {
                 Log::error("Gemini API - No text content found for {$this->originalName}");
                 $responseText = $this->extractTextFromGeminiResponse($response->body());
                 if (!$responseText) { $this->fail('AI response structure invalid'); return; }
                 Log::warning("Used fallback text extraction for {$this->originalName}");
            }
            $cleanedJsonString = preg_replace('/^\s*```json\s*|\s*```\s*$/', '', trim($responseText));
            $finalParsedData = json_decode($cleanedJsonString, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Gemini JSON Decode Error: " . json_last_error_msg() . ' | Cleaned: ' . $cleanedJsonString);
                $this->fail('Failed to parse AI JSON response'); return;
            }

            // 3. Extract Generated Answers and Save to PDF File
            $generatedAnswersText = $finalParsedData['generatedAnswers'] ?? null;
            $answersFilename = null; 

            if (!empty($generatedAnswersText)) {
                // filename(answers).pdf for answers generated
                $originalBasename = pathinfo($this->originalName, PATHINFO_FILENAME);
                $safeBasename = preg_replace('/[^A-Za-z0-9_-]/', '_', $originalBasename);
                $answersFilename = $safeBasename . '(answers).pdf';
                $answersFilePath = self::ANSWERS_DIRECTORY . '/' . $answersFilename;

                try {
                    $pdfContent = '<html><head><style>body { font-family: sans-serif; } pre { white-space: pre-wrap; word-wrap: break-word; }</style></head><body><pre>' . htmlspecialchars($generatedAnswersText) . '</pre></body></html>';
                    $pdfOutput = Pdf::loadHTML($pdfContent)->output();

                    // Save the generated PDF content to the public disk
                    Storage::disk('public')->put($answersFilePath, $pdfOutput);

                    Log::info("Saved generated answers PDF for {$this->originalName} to public disk at: {$answersFilePath}");

                } catch (Exception $e) {
                    Log::error("Failed to generate or save answers PDF for {$this->originalName} to {$answersFilePath}: " . $e->getMessage());
                    $this->fail("Failed to save answers PDF: " . $e->getMessage());
                    return; 
                }
            } else {
                Log::warning("No generated answers found in AI response for {$this->originalName}.");
            }

            // 4. Save Metadata and Answers PDF Filename to Database
            $imageFilename = $this->imagePublicPath ? basename($this->imagePublicPath) : null;

            Exam::create([
                'examName' => $finalParsedData['examName'] ?? $this->originalName,
                'examiner' => $finalParsedData['examiner'] ?? null,
                'subject' => $finalParsedData['subject'] ?? null,
                'class' => $finalParsedData['class'] ?? null,
                'term' => isset($finalParsedData['term']) && is_numeric($finalParsedData['term']) ? (int)$finalParsedData['term'] : null,
                'year' => $finalParsedData['year'] ?? null,
                'curriculum' => $finalParsedData['curriculum'] ?? null,
                'type' => $finalParsedData['type'] ?? null,
                'answers' => $answersFilename,
                'image' => $imageFilename,
            ]);

            Log::info("Successfully processed and saved data for: {$this->originalName}");

            // 5. Cleanup: Delete the temporary uploaded file (PDF/DOCX)
            if (!$this->imagePublicPath && Storage::exists($this->filePath)) {
                 Storage::delete($this->filePath);
                 Log::info("Deleted temporary file: {$this->filePath}");
            }

        } catch (Throwable $e) {
            Log::error("Job failed for {$this->originalName}: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            $this->fail($e); 
        }
    }

    /**
     * Fallback method to extract text from Gemini response.
     */
    private function extractTextFromGeminiResponse(string $responseBody): ?string
    {
        if (preg_match('/"text":\s*"(.+?)"/s', $responseBody, $matches)) {
             return json_decode('"' . $matches[1] . '"');
        }
        return null;
    }

    /**
      * Placeholder for OCR implementation.
      */
     protected function performOcr(string $imagePath): ?string
     {
         Log::warning("OCR processing attempted for $imagePath, but OCR library/logic is not fully implemented/enabled.");
         return null;
     }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::critical("Job ProcessExamFile FAILED for file {$this->originalName}. Error: {$exception->getMessage()}");

        // Delete generated PDF answers file if the job fails permanently
        try {
            $originalBasename = pathinfo($this->originalName, PATHINFO_FILENAME);
            $safeBasename = preg_replace('/[^A-Za-z0-9_-]/', '_', $originalBasename);
            $answersFilename = $safeBasename . '(answers).pdf';
            $answersFilePath = self::ANSWERS_DIRECTORY . '/' . $answersFilename;

            if (Storage::disk('public')->exists($answersFilePath)) {
                Storage::disk('public')->delete($answersFilePath);
                Log::info("Cleaned up answers PDF file after job failure: {$answersFilePath}");
            }
        } catch(Exception $e) {
            Log::error("Error during cleanup of answers PDF file for failed job {$this->originalName}: " . $e->getMessage());
        }
    }
}
