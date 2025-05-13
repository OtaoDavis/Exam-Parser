<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessExamFile;
use App\Models\Exam;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ParsingController extends Controller
{
    public function create()
    {
        $exams = Exam::with('questions')->latest()->paginate(15);
        return view('parser', compact('exams'));
    }

    public function store(Request $request)
    {

        $request->validate([

            'attach' => 'required|file|mimes:pdf,docx,jpg,jpeg,png|max:10240',
        ]);

        $file = $request->file('attach');
        $originalName = $file->getClientOriginalName();
        $safeOriginalName = preg_replace('/[^A-Za-z0-9\._-]/', '_', $originalName);
        $extension = $file->getClientOriginalExtension();

        $storedPath = null;
        $imagePublicPath = null;

        try {
            if (in_array($extension, ['jpg', 'jpeg', 'png'])) {
                $filename = Str::uuid() . '.' . $extension;
                $imagePublicPath = $file->storeAs('exam_images', $filename, 'public');

                if (!$imagePublicPath) {
                    throw new Exception('Failed to store image file on public disk.');
                }
                $storedPath = 'public/' . $imagePublicPath;

                Log::info("Stored image '{$safeOriginalName}' publicly at: {$imagePublicPath}");

            } elseif (in_array($extension, ['pdf', 'docx'])) {
                $filename = Str::uuid() . '.' . $extension;
                $storedPath = $file->storeAs('uploads', $filename);

                if (!$storedPath) {
                    throw new Exception('Failed to store document file on default disk.');
                }
                Log::info("Stored document '{$safeOriginalName}' privately at: {$storedPath}");

            } else {
                Log::warning("Attempted to upload unsupported file type '{$extension}' for '{$safeOriginalName}'.");
                return back()->with('error', 'Unsupported file type provided.');
            }

            ProcessExamFile::dispatch($storedPath, $originalName, $extension, $imagePublicPath);
            return redirect()->route('exams.upload')
                ->with('success', 'File uploaded successfully! It is now being processed in the background.');

        } catch (Exception $e) {
            Log::error("File upload or job dispatch failed for '{$safeOriginalName}': " . $e->getMessage(), [
                'exception' => $e
            ]);
            if ($storedPath) {
                if ($imagePublicPath) {
                    if (Storage::disk('public')->exists($imagePublicPath)) {
                        Storage::disk('public')->delete($imagePublicPath);
                        Log::info("Cleaned up failed public upload: {$imagePublicPath}");
                    }
                } else {
                    if (Storage::exists($storedPath)) {
                        Storage::delete($storedPath);
                        Log::info("Cleaned up failed private upload: {$storedPath}");
                    }
                }
            }

            return back()->with('error', 'An error occurred during file upload. Please check the logs or try again.');
        }
    }
    
}