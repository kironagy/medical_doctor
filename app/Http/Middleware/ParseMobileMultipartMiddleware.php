<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class ParseMobileMultipartMiddleware
{
    /**
     * Handle an incoming request.
     *
     * In NativePHP Android WebView environment:
     * 1. The C SAPI wrapper (php_bridge.c) forces SG(request_info).content_type
     *    to 'application/x-www-form-urlencoded' for non-JSON requests.
     * 2. Chromium request headers and JS-generated FormData payloads may use
     *    different boundary strings.
     *
     * This middleware inspects raw php://input stream for multipart/form-data
     * payloads, extracts fields and file chunks, and populates $request->request
     * and $request->files so Laravel controllers receive fully populated inputs.
     */
    public function handle(Request $request, Closure $next)
    {
        // ── 0. Clean stale output buffers from previous dispatches ─────────────
        // Prevents response pollution in persistent PHP runtime mode where previous
        // dispatch responses (e.g. sync engine JSON) leak into current request output.
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $contentType = $request->header('content-type') ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '';

        // Run whenever request is a POST/PUT/PATCH mutation
        if ($request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('PATCH')) {
            // Check if Content-Type specifies multipart OR raw content starts with multipart boundary
            $rawInput = $request->getContent();
            if (!empty($rawInput) && (str_contains($contentType, 'multipart/form-data') || str_starts_with(ltrim($rawInput), '--'))) {
                $this->parseMultipart($request, $contentType, $rawInput);
            }
        }

        return $next($request);
    }

    private function parseMultipart(Request $request, string $contentType, string $rawInput): void
    {
        // ── 1. Determine Boundary ───────────────────────────────────────────
        $boundary = null;
        if (preg_match('/boundary=(?:["\']?)([^"\';\s\r\n]+)(?:["\']?)/i', $contentType, $matches)) {
            $boundary = $matches[1];
        }

        // Fallback: extract boundary directly from the first line of raw payload
        if (!$boundary || !str_contains($rawInput, '--' . $boundary)) {
            if (preg_match('/^--([^\r\n]+)/', ltrim($rawInput), $firstLineMatches)) {
                $boundary = trim($firstLineMatches[1]);
                // Remove trailing -- if matched end boundary
                if (str_ends_with($boundary, '--')) {
                    $boundary = substr($boundary, 0, -2);
                }
            }
        }

        if (!$boundary) {
            return;
        }

        $delimiter = '--' . $boundary;
        if (!str_contains($rawInput, $delimiter)) {
            return;
        }

        Log::info('[ParseMobileMultipart] Processing multipart payload', [
            'url'          => $request->fullUrl(),
            'boundary'     => $boundary,
            'body_length'  => strlen($rawInput),
        ]);

        // ── 2. Split Payload by Boundary ────────────────────────────────────
        $parts = explode($delimiter, $rawInput);
        $textFields = [];

        foreach ($parts as $part) {
            $part = ltrim($part, "\r\n");
            if (empty($part) || str_starts_with($part, '--')) {
                continue;
            }

            // Separate headers from body section
            $split = preg_split('/\r?\n\r?\n/', $part, 2);
            if (count($split) < 2) {
                continue;
            }

            $headerBlock = $split[0];
            $body = $split[1];

            // Strip trailing boundary dashes / newlines from body
            $body = preg_replace('/\r?\n$/', '', $body);

            $headerLines = explode("\n", $headerBlock);
            $contentDisposition = '';
            $fieldMime = 'application/octet-stream';

            foreach ($headerLines as $line) {
                $line = trim($line);
                if (stripos($line, 'Content-Disposition:') === 0) {
                    $contentDisposition = $line;
                } elseif (stripos($line, 'Content-Type:') === 0) {
                    $fieldMime = trim(substr($line, strlen('Content-Type:')));
                }
            }

            if (empty($contentDisposition)) {
                continue;
            }

            // Extract field name
            if (!preg_match('/name=(?:["\']?)(.*?)(?:["\']?)(?:;|\r|\n|$)/i', $contentDisposition, $nameMatches)) {
                continue;
            }
            $fieldName = trim($nameMatches[1]);

            // ── 3. File Field vs Text Field ─────────────────────────────────
            if (preg_match('/filename=(?:["\']?)(.*?)(?:["\']?)(?:;|\r|\n|$)/i', $contentDisposition, $fileMatches) && !empty($fileMatches[1])) {
                $filename = trim($fileMatches[1]);

                // Write file chunk to temporary storage
                $tmpPath = tempnam(sys_get_temp_dir(), 'nphp_upl_');
                file_put_contents($tmpPath, $body);

                $uploadedFile = new UploadedFile(
                    $tmpPath,
                    $filename,
                    $fieldMime,
                    null,
                    true // test mode = true bypasses is_uploaded_file() validation
                );

                $request->files->set($fieldName, $uploadedFile);
                Log::info("[ParseMobileMultipart] Attached file: {$fieldName} ({$filename}, " . strlen($body) . " bytes)");
            } else {
                // Text field
                $textFields[$fieldName] = $body;
                $_POST[$fieldName] = $body;
                Log::info("[ParseMobileMultipart] Extracted field: {$fieldName} = {$body}");
            }
        }

        // Merge text fields into Laravel's request input source so $request->all(),
        // $request->input(), and $request->validate() access them directly.
        if (!empty($textFields)) {
            $request->merge($textFields);
            Log::info('[ParseMobileMultipart] Merged fields into request:', array_keys($textFields));
        }
    }
}
