<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;
use PhpOffice\PhpWord\IOFactory;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
   public function upload(Request $request)
{
    $request->validate([
        'file' => 'required|file|mimes:pdf,docx|max:10240',
    ]);

    $path = $request->file('file')->store('documents');
    $fullPath = storage_path('app/' . $path);
    $extension = $request->file('file')->getClientOriginalExtension();

    $extractedText = '';

    if ($extension === 'pdf') {
        $parser = new Parser();
        $pdf = $parser->parseFile($fullPath);
        $extractedText = $pdf->getText();
    } elseif ($extension === 'docx') {
        $phpWord = IOFactory::load($fullPath);
        foreach ($phpWord->getSections() as $section) {
            $elements = $section->getElements();
            foreach ($elements as $element) {
                if (method_exists($element, 'getText')) {
                    $extractedText .= $element->getText() . "\n";
                }
            }
        }
    } else {
        $extractedText = '[Text extraction not supported for this file type]';
    }

    return response()->json([
        'path' => $path,
        'extractedText' => $extractedText,
    ]);
}

    public function generateReview(Request $request)
    {
        try {
            $content = $request->input('content');  // Extracted text from file

            $response = Http::withHeaders([
                'api-key' => env('AZURE_OPENAI_API_KEY'),
                'Content-Type' => 'application/json',
            ])->post(env('AZURE_OPENAI_ENDPOINT') . 'openai/deployments/' . env('AZURE_OPENAI_DEPLOYMENT_NAME') . '/chat/completions?api-version=' . env('AZURE_OPENAI_API_VERSION'), [
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a helpful assistant that generates long, detailed, and comprehensive study reviewers.'],
                    ['role' => 'user', 'content' => 'Please generate a detailed study reviewer based on the following content: ' . $content]
                ],
                'max_tokens' => 1500,  // Increased token count
                'temperature' => 0.7, 
            ]);


            $data = $response->json();

            if (!$response->successful()) {
                Log::error('Azure API Error: ' . $response->body());
                return response()->json(['error' => 'Azure API request failed', 'details' => $response->json()], 500);
            }

            return response()->json($data);

        } catch (\Exception $e) {
            Log::error('Azure Exception: ' . $e->getMessage());
            return response()->json(['error' => 'Server Error', 'message' => $e->getMessage()], 500);
        }
    }

    public function downloadReview(Request $request)
    {
        $text = $request->input('text');
        $filename = 'review_' . now()->timestamp . '.txt';

        return response($text)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }


}
