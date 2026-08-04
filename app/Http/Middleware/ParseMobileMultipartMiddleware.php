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

        // Raise memory limit as a secondary safeguard
        @ini_set('memory_limit', '256M');

        $contentType = $request->header('content-type') ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '';

        // ── Auto-login local user on embedded SQLite (mobile app) ──────────
        // Embedded Laravel runtime on Android is single-user native mobile app.
        // Auto-logging in the local doctor user when guest ensures that offline
        // page requests (/workspace, /dashboard) NEVER redirect to /login or fail with 404.
        if (config('database.default') === 'sqlite' && \Illuminate\Support\Facades\Auth::guest() && \App\Http\Controllers\AuthController::deviceIsAuthenticated()) {
            $localUser = \App\Domains\Users\Models\User::first();
            if ($localUser) {
                \Illuminate\Support\Facades\Auth::login($localUser);
            }
        }

        // Run whenever request is a POST/PUT/PATCH mutation
        if ($request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('PATCH')) {
            // ── PERF FIX: Skip manual parsing when Symfony already parsed the
            // files. On a normal multipart request, PHP populates $_FILES at
            // request creation, so $request->files is populated BEFORE this
            // middleware runs. Only the broken Android WebView case (content
            // type forced to x-www-form-urlencoded) leaves files empty — that
            // is the ONLY case that needs the manual parser.
            $alreadyParsed = $request->files->count() > 0;
            if (!$alreadyParsed) {
                $this->parseMultipartStream($request, $contentType);
            }
        }

        return $next($request);
    }

    /**
     * Memory-efficient stream parser for multipart/form-data requests.
     * Reads directly from php://input in 64KB buffer chunks to eliminate PHP memory exhaustion.
     */
    private function parseMultipartStream(Request $request, string $contentType): void
    {
        $stream = @fopen('php://input', 'rb');
        if (!$stream) {
            return;
        }

        // ── 1. Determine Boundary ───────────────────────────────────────────
        $boundary = null;
        if (preg_match('/boundary=(?:["\']?)([^"\';\s\r\n]+)(?:["\']?)/i', $contentType, $matches)) {
            $boundary = $matches[1];
        }

        if (!$boundary) {
            $firstLine = fgets($stream);
            if ($firstLine && preg_match('/^--([^\r\n]+)/', ltrim($firstLine), $firstLineMatches)) {
                $boundary = trim($firstLineMatches[1]);
                if (str_ends_with($boundary, '--')) {
                    $boundary = substr($boundary, 0, -2);
                }
            }
            fseek($stream, 0);
        }

        if (!$boundary) {
            fclose($stream);
            return;
        }

        $boundaryDelimiter = '--' . $boundary;

        Log::info('[ParseMobileMultipartStream] Processing multipart stream', [
            'url'      => $request->fullUrl(),
            'boundary' => $boundary,
        ]);

        $textFields = [];
        $chunkSize = 65536; // 64KB read chunks

        // Skip until first boundary
        while (!feof($stream)) {
            $line = fgets($stream);
            if ($line === false) break;
            if (str_contains($line, $boundaryDelimiter)) {
                break;
            }
        }

        while (!feof($stream)) {
            // ── Read Part Headers ───────────────────────────────────────────
            $headerLines = [];
            while (!feof($stream)) {
                $line = fgets($stream);
                if ($line === false) break;
                $trimmed = trim($line);
                if ($trimmed === '') {
                    break;
                }
                $headerLines[] = $trimmed;
            }

            if (empty($headerLines)) {
                break;
            }

            $contentDisposition = '';
            $fieldMime = 'application/octet-stream';
            $isBase64 = false;

            foreach ($headerLines as $line) {
                if (stripos($line, 'Content-Disposition:') === 0) {
                    $contentDisposition = $line;
                } elseif (stripos($line, 'Content-Type:') === 0) {
                    $fieldMime = trim(substr($line, strlen('Content-Type:')));
                } elseif (stripos($line, 'Content-Transfer-Encoding:') === 0) {
                    $isBase64 = stripos($line, 'base64') !== false;
                }
            }

            if (empty($contentDisposition)) {
                continue;
            }

            if (!preg_match('/name=(?:["\']?)(.*?)(?:["\']?)(?:;|\r|\n|$)/i', $contentDisposition, $nameMatches)) {
                continue;
            }
            $fieldName = trim($nameMatches[1]);

            $isFilename = preg_match('/filename=(?:["\']?)(.*?)(?:["\']?)(?:;|\r|\n|$)/i', $contentDisposition, $fileMatches) && !empty($fileMatches[1]);
            $filename = $isFilename ? trim($fileMatches[1]) : null;

            // ── Read Body for Part ──────────────────────────────────────────
            $delimiterToFind = "\r\n--" . $boundary;
            $delimLen = strlen($delimiterToFind);

            if ($isFilename && $filename) {
                $tmpPath = tempnam(sys_get_temp_dir(), 'nphp_upl_');
                $tmpFp = fopen($tmpPath, 'wb');
                $partBuffer = '';
                $base64Remainder = '';

                while (!feof($stream)) {
                    $read = fread($stream, $chunkSize);
                    if ($read === false || $read === '') break;
                    $partBuffer .= $read;

                    $pos = strpos($partBuffer, $delimiterToFind);
                    if ($pos !== false) {
                        $bodyData = substr($partBuffer, 0, $pos);
                        $this->writeBodyData($tmpFp, $bodyData, $isBase64, $base64Remainder, true);
                        $remaining = substr($partBuffer, $pos + strlen("\r\n"));
                        $partBuffer = '';
                        break;
                    } else {
                        if (strlen($partBuffer) > $delimLen) {
                            $writeLen = strlen($partBuffer) - $delimLen;
                            $bodyData = substr($partBuffer, 0, $writeLen);
                            $partBuffer = substr($partBuffer, $writeLen);
                            $this->writeBodyData($tmpFp, $bodyData, $isBase64, $base64Remainder, false);
                        }
                    }
                }

                if ($partBuffer !== '') {
                    $pos = strpos($partBuffer, "--" . $boundary);
                    if ($pos !== false) {
                        $bodyData = substr($partBuffer, 0, $pos);
                        $bodyData = preg_replace('/\r?\n$/', '', $bodyData);
                        $this->writeBodyData($tmpFp, $bodyData, $isBase64, $base64Remainder, true);
                    } else {
                        $this->writeBodyData($tmpFp, $partBuffer, $isBase64, $base64Remainder, true);
                    }
                }

                fclose($tmpFp);

                $uploadedFile = new UploadedFile(
                    $tmpPath,
                    $filename,
                    $fieldMime,
                    null,
                    true // test mode = true bypasses is_uploaded_file() validation
                );

                $request->files->set($fieldName, $uploadedFile);
                Log::info("[ParseMobileMultipartStream] Attached file: {$fieldName} ({$filename}, " . filesize($tmpPath) . " bytes)");
            } else {
                $partData = '';
                while (!feof($stream)) {
                    $line = fgets($stream);
                    if ($line === false) break;
                    if (str_contains($line, $boundaryDelimiter)) {
                        break;
                    }
                    $partData .= $line;
                }
                $partData = preg_replace('/\r?\n$/', '', $partData);
                $textFields[$fieldName] = $partData;
                $_POST[$fieldName] = $partData;
                Log::info("[ParseMobileMultipartStream] Extracted text field: {$fieldName}");
            }
        }

        fclose($stream);

        if (!empty($textFields)) {
            $request->merge($textFields);
            Log::info('[ParseMobileMultipartStream] Merged text fields into request:', array_keys($textFields));
        }
    }

    /**
     * Write part body chunk to file stream, handling base64 decoding if required.
     */
    private function writeBodyData($fp, string $data, bool $isBase64, string &$base64Remainder, bool $isFinal): void
    {
        if (!$isBase64) {
            fwrite($fp, $data);
            return;
        }

        $cleaned = str_replace(["\r", "\n"], '', $data);
        $full = $base64Remainder . $cleaned;

        if ($isFinal) {
            $decoded = base64_decode($full, true);
            if ($decoded !== false && $decoded !== '') {
                fwrite($fp, $decoded);
            }
            $base64Remainder = '';
        } else {
            $len = strlen($full);
            $validLen = $len - ($len % 4);
            if ($validLen > 0) {
                $toDecode = substr($full, 0, $validLen);
                $base64Remainder = substr($full, $validLen);
                $decoded = base64_decode($toDecode, true);
                if ($decoded !== false && $decoded !== '') {
                    fwrite($fp, $decoded);
                }
            } else {
                $base64Remainder = $full;
            }
        }
    }
}
