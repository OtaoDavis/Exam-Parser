<?php

namespace App\Jobs;

use App\Models\Exam;
use App\Models\ExamQuestionAnswer;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\Stopwatch\Stopwatch;
use Throwable;

class ProcessExamFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $filePath;
    protected string $originalName;
    protected string $fileExtension;
    protected ?string $imagePublicPath;

    protected const EXAM_IMAGES_DIRECTORY = 'exam_images';

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
        $stopwatch = new Stopwatch();
        $stopwatch->start('exam_processing');
        Log::info("Processing job for file: {$this->originalName} ({$this->filePath})");

        $extractedText = null;
        $fullStoragePath = storage_path('app/' . $this->filePath);

        try {
            // 1. Text Extraction
            if (in_array($this->fileExtension, ['jpg', 'jpeg', 'png'])) {
                Log::warning("OCR processing needed for image {$this->originalName} but is not implemented.");
                $extractedText = $this->performOcr($fullStoragePath); 
            } elseif ($this->fileExtension === 'pdf') {
                if (!Storage::exists($this->filePath)) throw new Exception("PDF file not found at path: " . $this->filePath);
                $parser = new PdfParser();
                $pdf = $parser->parseFile($fullStoragePath);
                $extractedText = $pdf->getText();
                Log::info("Extracted text from PDF: " . Str::limit($extractedText, 100));
            } elseif ($this->fileExtension === 'docx') {
                if (!Storage::exists($this->filePath)) throw new Exception("DOCX file not found at path: " . $this->filePath);
                $phpWord = IOFactory::load($fullStoragePath);
                $text = '';
                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        if (method_exists($element, 'getText')) $text .= $element->getText() . ' ';
                    }
                }
                $extractedText = trim($text);
                Log::info("Extracted text from DOCX: " . Str::limit($extractedText, 100));
            } else {
                Log::error("Unsupported file type: {$this->fileExtension}");
                return;
            }

            if (empty($extractedText) && !$this->imagePublicPath) {
                Log::error("Could not extract text: {$this->originalName}");
                $this->fail("Text extraction failed");
                return;
            }
            if ($this->imagePublicPath && empty($extractedText)) {
                Log::error("Cannot generate answers for image {$this->originalName} without OCR text.");
                $this->fail("OCR text needed for image answers");
                return;
            }

            // 2. Call Gemini API for Questions and Answers
            $apiKey = env('GEMINI_API_KEY');
            if (!$apiKey) {
                Log::error('Gemini API key missing');
                $this->fail('Gemini API key missing');
                return;
            }

            $prompt = "Analyze the following exam paper content. First, extract metadata (examName, examiner, subject, class, term, year, curriculum, type). For examiner, it's usually the first description in bold at the top of the paper. For curriculum data, CBC papers are labelled CBC, if not labelled cbc, it's 844. Second, identify each question, including any sub-parts (e.g., 1a, 1b) but be carefull in this, some questions have multiple answer underlines below them and you might confuse them for a multilevel question. For each identified question, provide a concise answer. If the question is a True/False question, answer with 'True' or 'False'. For fill-in-the-blanks questions (indicated by underscores), fill in the blank spaces with the correct word(s) based on your knowledge. For other question types, generate the answer based on your knowledge of the subject matter and the question text.Include the generated answer in the 'answer' field of the JSON response. If there are multiple subject included in one papaer separate them as well. If a question refers to an image, note that. Provide the output strictly in JSON format (double quotes) with the following structure:\n\n{\n  \"examName\": \"...\",\n  \"examiner\": \"...\",\n  \"subject\": \"...\",\n  \"class\": \"...\",\n  \"term\": \"...\",\n  \"year\": \"...\",\n  \"curriculum\": \"...\",\n  \"type\": \"...\",\n  \"questions\": [\n    {\n      \"question_number\": 1,\n      \"question_sub_part\": null,\n      \"question\": \"What is the capital of Kenya?\",\n      \"answer\": \"Nairobi.\",\n      \"has_image\": false\n    },\n    {\n      \"question_number\": 2,\n      \"question_sub_part\": \"a\",\n      \"question\": \"Identify the parts labelled A and B in the diagram.\",\n      \"answer\": \"A: ..., B: ...\",\n      \"has_image\": true\n    },\n    // ... more questions\n  ]\n}\n\nUse null if metadata or sub-part is not found. Ensure 'question_number' is an integer and 'has_image' is a boolean.\n\nExam Content:\n```\n" . substr($extractedText, 0, 25000) . "\n```\n\nJSON Output:";

            $response = Http::withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                ->timeout(180)
                ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $apiKey, [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['responseMimeType' => 'application/json'],
                ]);

            Log::debug("Gemini API Raw Response for {$this->originalName}: " . $response->body());
            if ($response->failed()) {
                Log::error("Gemini API HTTP Error {$response->status()}");
                $this->fail('Gemini API failed');
                return;
            }

            $parsedData = $response->json();
            Log::debug('Parsed Gemini API JSON structure: ' . json_encode($parsedData));
            $responseText = data_get($parsedData, 'candidates.0.content.parts.0.text');
            if (!$responseText) {
                Log::warning("Gemini API - Primary extraction failed for {$this->originalName}. Attempting fallback extraction.");
                $rawBody = $response->body();
            
                // Try to extract JSON from Markdown code block or similar format
                if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $rawBody, $matches)) {
                    $responseText = $matches[1];
                    Log::info("Fallback JSON extracted from markdown block.");
                } else {
                    // Try to locate JSON object manually
                    if (preg_match('/\{.*\}/s', $rawBody, $matches)) {
                        $responseText = $matches[0];
                        Log::info("Fallback JSON extracted from raw body.");
                    }
                }
            
                if (!$responseText) {
                    Log::error("Could not extract JSON from Gemini response for {$this->originalName}");
                    $this->fail("AI response structure invalid");
                    return;
                }
            }


            // Sanitize the JSON string (remove potential extra commas, etc.)
            $sanitizedJsonString = trim($responseText);
            if (substr($sanitizedJsonString, -1) === ',') {
                $sanitizedJsonString = substr($sanitizedJsonString, 0, -1);
            }

            // Decode the outer JSON string
            $outerData = json_decode($sanitizedJsonString, true);
            Log::debug("Parsed Gemini JSON: " . json_encode($outerData));

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("JSON Decode Error: " . json_last_error_msg() . ' | Raw JSON: ' . $sanitizedJsonString);
                $this->fail('Failed to decode Gemini JSON');
                return;
            }

            $examsExtracted = [];
            // Check if the outer data is already an array of exams
            if (is_array($outerData) && isset($outerData[0]['examName'])) {
                $examsExtracted = $outerData;
            } elseif (is_array($outerData) && isset($outerData[0]['text'])) {
                // If it has a 'text' key, try decoding that as the array of exams
                $examDataArrayString = $outerData[0]['text'] ?? null;
                if ($examDataArrayString) {
                    $decodedExams = json_decode($examDataArrayString, true);
                    if (is_array($decodedExams) && isset($decodedExams[0]['examName'])) {
                        $examsExtracted = $decodedExams;
                    } else {
                        Log::error("Could not decode exam data array from 'text' key for {$this->originalName}: " . $examDataArrayString);
                        $this->fail('Failed to decode exam data array from text');
                        return;
                    }
                } else {
                    Log::error("Could not find 'text' key in AI response for {$this->originalName}");
                    $this->fail('Failed to find exam data array');
                    return;
                }
            } else {
                Log::error("Unexpected structure in AI response for {$this->originalName}: " . json_encode($outerData));
                $this->fail('Unexpected AI response structure');
                return;
            }

            foreach ($examsExtracted as $examExtracted) {
                // 3. Save Metadata to Exams Table
                $exam = Exam::create([
                    'examName' => $examExtracted['examName'] ?? $this->originalName,
                    'examiner' => $examExtracted['examiner'] ?? null,
                    'subject' => $examExtracted['subject'] ?? null,
                    'class' => $examExtracted['class'] ?? null,
                    'term' => isset($examExtracted['term']) && is_numeric($examExtracted['term']) ? (int)$examExtracted['term'] : null,
                    'year' => $examExtracted['year'] ?? null,
                    'curriculum' => $examExtracted['curriculum'] ?? null,
                    'type' => $examExtracted['type'] ?? null,
                    'processing_time' => $processingTime ?? null,
                ]);

                // 4. Save Questions and Answers to ExamQuestionAnswers Table
                $questions = $examExtracted['questions'] ?? [];
                foreach ($questions as $questionData) {
                    if (!isset($questionData['question_number']) || !isset($questionData['question']) || !isset($questionData['answer'])) {
                        Log::warning("Skipping question due to missing essential data: " . json_encode($questionData));
                        continue; 
                    }

                    $imageFilename = null;
                    if ($questionData['has_image'] && $this->imagePublicPath) {
                        $originalImageName = basename($this->imagePublicPath);
                        $uniqueImageName = Str::uuid() . '_' . $originalImageName;
                        $imageStoragePath = self::EXAM_IMAGES_DIRECTORY . '/' . $uniqueImageName;

                        // Copy the image to the exam_images directory
                        if (Storage::disk('public')->exists($this->imagePublicPath)) {
                            Storage::disk('public')->copy($this->imagePublicPath, $imageStoragePath);
                            $imageFilename = $uniqueImageName;
                            Log::info("Saved image for exam {$exam->id}, question {$questionData['question_number']} to: {$imageStoragePath}");
                        } else {
                            Log::warning("Image file not found at public path: {$this->imagePublicPath}");
                        }
                    }

                    ExamQuestionAnswer::create([
                        'exam_id' => $exam->id,
                        'question_number' => $questionData['question_number'],
                        'question_sub_part' => $questionData['question_sub_part'] ?? null,
                        'question' => $questionData['question'],
                        'answer' => $questionData['answer'],
                        'image' => $imageFilename,
                    ]);
                }
            }

            Log::info("Successfully processed and saved data for: {$this->originalName}");

            $event = $stopwatch->stop('exam_processing');
            $processingTime = $event->getDuration() / 1000; // in seconds
            Log::info("Processing time for {$this->originalName}: {$processingTime} seconds");
            $this->processingTime = $processingTime;

            // 5. Cleanup: Delete the temporary uploaded file (PDF/DOCX)
            if (!$this->imagePublicPath && Storage::exists($this->filePath)) {
                Storage::delete($this->filePath);
                Log::info("Deleted temporary file: {$this->filePath}");
            }

            // 6. Cleanup: Optionally delete the temporary image file if it was uploaded separately
            if ($this->imagePublicPath && Storage::disk('public')->exists($this->imagePublicPath)) {
                Storage::disk('public')->delete($this->imagePublicPath);
                Log::info("Deleted temporary image file: {$this->imagePublicPath}");
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

    }
}