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

class ProcessExamFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $filePath;
    protected string $originalName;
    protected string $fileExtension;
    protected ?string $imagePublicPath;

    /**
     * Create a new job instance.
     *
     * @param string $filePath Path to the file within storage/app
     * @param string $originalName Original name of the uploaded file
     * @param string $fileExtension The file extension
     * @param string|null $imagePublicPath Publicly accessible path if it's an image
     * @return void
     */
    public function __construct(string $filePath, string $originalName, string $fileExtension, ?string $imagePublicPath = null)
    {
        $this->filePath = $filePath;
        $this->originalName = $originalName;
        $this->fileExtension = $fileExtension;
        $this->imagePublicPath = $imagePublicPath;
    }

    /**
     * Execute job.
     *
     * @return void
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
                if (!file_exists($fullStoragePath)) {
                     throw new Exception("PDF file not found at path: " . $fullStoragePath);
                }
                $parser = new PdfParser();
                $pdf = $parser->parseFile($fullStoragePath);
                $extractedText = $pdf->getText();
                Log::info("Extracted text from PDF: " . Str::limit($extractedText, 100));


            } elseif ($this->fileExtension === 'docx') {
                 if (!file_exists($fullStoragePath)) {
                     throw new Exception("DOCX file not found at path: " . $fullStoragePath);
                 }
                 $phpWord = IOFactory::load($fullStoragePath);
                 $text = '';
                 foreach ($phpWord->getSections() as $section) {
                     foreach ($section->getElements() as $element) {
                        if ($element instanceof \PhpOffice\PhpWord\Element\TextRun || $element instanceof \PhpOffice\PhpWord\Element\Text) {
                            $text .= $element->getText() . ' ';
                        } elseif ($element instanceof \PhpOffice\PhpWord\Element\ListItemRun) {
                            $text .= $element->getText() . ' '; 
                        } elseif ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                             foreach ($element->getRows() as $row) {
                                 foreach ($row->getCells() as $cell) {
                                     foreach ($cell->getElements() as $cellElement) {
                                         if (method_exists($cellElement, 'getText')) {
                                             $text .= $cellElement->getText() . ' ';
                                         }
                                     }
                                 }
                             }
                         }
                     }
                 }
                 $extractedText = trim($text);
                 Log::info("Extracted text from DOCX: " . Str::limit($extractedText, 100));

            } else {
                Log::error("Unsupported file type encountered in job: {$this->fileExtension}");
                return; 
            }

             if (empty($extractedText) && !$this->imagePublicPath) {
                 Log::error("Could not extract text from file: {$this->originalName}");
                 // delete file(optimize storage)
                 Storage::delete($this->filePath);
                 return;
             }
            $apiKey = env('DEEPSEEK_API_KEY');
            if (!$apiKey) {
                Log::error('DeepSeek API key is not configured. Job failed for: ' . $this->originalName);
                $this->fail('DeepSeek API key is not configured.');
                return;
            }
            $contentForPrompt = $extractedText ?? 'N/A - Image file provided. Public path: ' . ($this->imagePublicPath ?? 'N/A');
            $prompt = "Parse the following exam content and extract the specified fields. Provide the output strictly in JSON format with keys: 'examName', 'examiner', 'subject', 'class', 'term', 'year', 'curriculum', 'type', 'answers'. If a field cannot be determined, use null or an empty string for its value.\n\nExam Content:\n```\n" . substr($contentForPrompt, 0, 15000) . "\n```\n\nJSON Output:";

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(120)
              ->post('https://api.deepseek.com/v1/chat/completions', [
                'model' => 'deepseek-chat',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are an assistant that parses exam documents and returns structured data in JSON format.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.2,
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->failed()) {
                Log::error("DeepSeek API Error for {$this->originalName}: " . $response->status() . ' - ' . $response->body());
                $this->fail('Failed to communicate with the AI service. Status: ' . $response->status());
                return;
            }

            $jsonResponse = $response->json('choices.0.message.content');

            if (!$jsonResponse) {
                 Log::error("DeepSeek API - No content found for {$this->originalName}: " . $response->body());
                 $this->fail('AI service did not return valid content.');
                 return;
            }

            $parsedData = json_decode(trim($jsonResponse), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("DeepSeek API - JSON Decode Error for {$this->originalName}: " . json_last_error_msg() . ' | Raw: ' . $jsonResponse);
                $this->fail('Failed to parse the data received from the AI service.');
                return;
            }

            // 3. Save Data to Database 
            Exam::create([
                'examName' => $parsedData['examName'] ?? $this->originalName, 
                'examiner' => $parsedData['examiner'] ?? null,
                'subject' => $parsedData['subject'] ?? null,
                'class' => $parsedData['class'] ?? null,
                'term' => isset($parsedData['term']) ? (int)$parsedData['term'] : null,
                'year' => $parsedData['year'] ?? null,
                'curriculum' => $parsedData['curriculum'] ?? null,
                'type' => $parsedData['type'] ?? null,
                'answers' => $parsedData['answers'] ?? null, 
                'image' => $this->imagePublicPath, 
            ]);

            Log::info("Successfully processed and saved data for: {$this->originalName}");
             if (!$this->imagePublicPath) { 
                 Storage::delete($this->filePath);
                 Log::info("Deleted temporary file: {$this->filePath}");
             }


        } catch (Throwable $e) { 
            Log::error("Job failed for {$this->originalName}: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            $this->fail($e);
        }
    }

     /**
      * OCR LIbrary for character recognintion
      * @param string $path to the image file in storage.
      * @return string|null 
      */
     protected function performOcr(string $imagePath): ?string
     {         
         try {
             $tesseract = new TesseractOCR($imagePath);
             return $tesseract->run();
         } catch (Exception $e) {
             Log::error("OCR Failed for $imagePath: " . $e->getMessage());
             return null;
         }
         Log::warning("OCR processing attempted for $imagePath, but no OCR library is implemented.");
         return null;
     }

    /**
     * job failure.
     *
     * @param  \Throwable
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        Log::critical("Job ProcessExamFile FAILED for file {$this->originalName}. Error: {$exception->getMessage()}");

    }
}
