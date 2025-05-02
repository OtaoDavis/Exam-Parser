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
use Throwable; // Import Throwable for catching errors in handle

class ProcessExamFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $filePath; // Path relative to storage/app
    protected string $originalName;
    protected string $fileExtension;
    protected ?string $imagePublicPath; // Path relative to public storage (for DB)

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
        $this->imagePublicPath = $imagePublicPath; // Store the public path for DB saving
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        Log::info("Processing job for file: {$this->originalName} ({$this->filePath})");

        $extractedText = null;
        $fullStoragePath = storage_path('app/' . $this->filePath); // Get absolute path for processing

        try {
            // 1. Text Extraction (Moved from Controller)
            if (in_array($this->fileExtension, ['jpg', 'jpeg', 'png'])) {
                // --- OCR Placeholder ---
                // Image file is already stored. Now perform OCR if needed.
                // $extractedText = $this->performOcr($fullStoragePath);
                Log::warning("OCR processing needed for image {$this->originalName} but is not implemented.");
                // If DeepSeek *can* handle image URLs/content directly, you'd pass
                // $this->imagePublicPath or the file content here instead of text.
                // For now, we proceed assuming text is needed but unavailable for images.
                // Consider failing the job or storing with minimal data if OCR isn't done.
                 // $fail('OCR is required for image processing and is not implemented.'); // Option to fail job
                 // return; // Or just stop processing this job

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
                         // Check element type for better text extraction
                        if ($element instanceof \PhpOffice\PhpWord\Element\TextRun || $element instanceof \PhpOffice\PhpWord\Element\Text) {
                            $text .= $element->getText() . ' ';
                        } elseif ($element instanceof \PhpOffice\PhpWord\Element\ListItemRun) {
                            $text .= $element->getText() . ' '; // Handle list items
                        } elseif ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                             // Basic table text extraction
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
                         // Add more element types as needed
                     }
                 }
                 $extractedText = trim($text);
                 Log::info("Extracted text from DOCX: " . Str::limit($extractedText, 100));

            } else {
                Log::error("Unsupported file type encountered in job: {$this->fileExtension}");
                // Optionally delete the file if it shouldn't have reached the job
                // Storage::delete($this->filePath);
                return; // Stop processing
            }

            // Check if text extraction is needed but failed (e.g., for non-image types)
             if (empty($extractedText) && !$this->imagePublicPath) {
                 Log::error("Could not extract text from file: {$this->originalName}");
                 // Optionally delete the file
                 // Storage::delete($this->filePath);
                 return; // Stop processing
             }


            // 2. Call DeepSeek AI for Parsing (Moved from Controller)
            $apiKey = env('DEEPSEEK_API_KEY');
            if (!$apiKey) {
                Log::error('DeepSeek API key is not configured. Job failed for: ' . $this->originalName);
                $this->fail('DeepSeek API key is not configured.'); // Fail the job
                return;
            }

            // Construct the prompt - use extracted text or indicate image path
            $contentForPrompt = $extractedText ?? 'N/A - Image file provided. Public path: ' . ($this->imagePublicPath ?? 'N/A');
            $prompt = "Parse the following exam content and extract the specified fields. Provide the output strictly in JSON format with keys: 'examName', 'examiner', 'subject', 'class', 'term', 'year', 'curriculum', 'type', 'answers'. If a field cannot be determined, use null or an empty string for its value.\n\nExam Content:\n```\n" . substr($contentForPrompt, 0, 15000) . "\n```\n\nJSON Output:";

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(120) // Increase timeout for potentially long AI responses
              ->post('https://api.deepseek.com/v1/chat/completions', [ // Replace with actual endpoint
                'model' => 'deepseek-chat', // Replace with actual model
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

            // 3. Save Data to Database (Moved from Controller)
            Exam::create([ // Using mass assignment now
                'examName' => $parsedData['examName'] ?? $this->originalName, // Use original name as fallback
                'examiner' => $parsedData['examiner'] ?? null,
                'subject' => $parsedData['subject'] ?? null,
                'class' => $parsedData['class'] ?? null,
                'term' => isset($parsedData['term']) ? (int)$parsedData['term'] : null,
                'year' => $parsedData['year'] ?? null,
                'curriculum' => $parsedData['curriculum'] ?? null,
                'type' => $parsedData['type'] ?? null,
                // Store answers as JSON string or use casting on the model
                'answers' => $parsedData['answers'] ?? null, // Assumes model casts to array/json
                'image' => $this->imagePublicPath, // Use the stored public image path
                // Add original filename if you have a column for it
                // 'original_filename' => $this->originalName,
            ]);

            Log::info("Successfully processed and saved data for: {$this->originalName}");

            // 4. Cleanup: Delete the temporary file from storage/app after processing
            // Keep the public image file in storage/app/public though!
             if (!$this->imagePublicPath) { // Only delete if it wasn't an image stored in public
                 Storage::delete($this->filePath);
                 Log::info("Deleted temporary file: {$this->filePath}");
             }


        } catch (Throwable $e) { // Catch Throwable for broader error coverage
            Log::error("Job failed for {$this->originalName}: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            // Fail the job so Laravel can retry it based on your queue config
            $this->fail($e);
        }
    }

     /**
      * Placeholder for OCR implementation.
      * Requires an OCR library like Tesseract.
      *
      * @param string $imagePath Full path to the image file in storage.
      * @return string|null Extracted text or null on failure.
      */
     protected function performOcr(string $imagePath): ?string
     {
         // Example using a hypothetical Tesseract wrapper
         /*
         try {
             $tesseract = new TesseractOCR($imagePath);
             return $tesseract->run();
         } catch (Exception $e) {
             Log::error("OCR Failed for $imagePath: " . $e->getMessage());
             return null;
         }
         */
         Log::warning("OCR processing attempted for $imagePath, but no OCR library is implemented.");
         return null; // Return null as OCR isn't implemented here
     }

    /**
     * Handle a job failure.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        // Send notification, log failure, etc.
        Log::critical("Job ProcessExamFile FAILED for file {$this->originalName}. Error: {$exception->getMessage()}");

        // Optionally: Clean up the originally uploaded file if the job fails permanently
        // Be careful with this logic, depends on your retry strategy.
        // if (Storage::exists($this->filePath)) {
        //     Storage::delete($this->filePath);
        //     Log::info("Deleted file {$this->filePath} after job failure.");
        // }
        // if ($this->imagePublicPath && Storage::disk('public')->exists($this->imagePublicPath)) {
        //     Storage::disk('public')->delete($this->imagePublicPath);
        //     Log::info("Deleted public image {$this->imagePublicPath} after job failure.");
        // }
    }
}
