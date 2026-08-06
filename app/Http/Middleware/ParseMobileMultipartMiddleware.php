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
        if (config('database.default') === 'sqlite' && \Illuminate\Support\Facades\Auth::guest()) {
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
     *
     * Reads php://input in 64KB chunks and keeps ONE carry-over buffer for the
     * whole payload. The previous version reset that buffer after every file
     * part (it computed a $remaining slice and then threw it away), so every
     * field that arrived AFTER a file was already sitting in the read-ahead
     * buffer and got discarded. FormData appends 'file' first and
     * 'patient_uuid' after it, which is exactly why offline uploads failed
     * with 422 "patient_uuid is required".
     */
    private function parseMultipartStream(Request $request, string $contentType): void
    {
        $stream = $this->openInputStream();
        if (!$stream) {
            return;
        }

        $chunkSize = 65536;
        $buf = '';
        $eof = false;

        // Top up $buf until it holds $need bytes or the stream runs dry.
        $fill = function (int $need) use (&$buf, &$eof, $stream, $chunkSize): void {
            while (!$eof && strlen($buf) < $need) {
                $read = fread($stream, $chunkSize);
                if ($read === false || $read === '') {
                    $eof = true;
                    break;
                }
                $buf .= $read;
            }
        };

        // ── 1. Determine boundary ───────────────────────────────────────────
        $boundary = null;
        if (preg_match('/boundary=(?:["\']?)([^"\';\s\r\n]+)(?:["\']?)/i', $contentType, $m)) {
            $boundary = $m[1];
        }

        if (!$boundary) {
            // The Android bridge forces CONTENT_TYPE to x-www-form-urlencoded
            // and drops the boundary, so recover it from the opening delimiter.
            $fill(4096);
            if (preg_match('/^--([^\r\n]+)\r?\n/', $buf, $m)) {
                $boundary = rtrim(trim($m[1]), '-');
            }
        }

        if (!$boundary) {
            fclose($stream);
            return;
        }

        $delimiter  = "\r\n--" . $boundary;
        $delimLen   = strlen($delimiter);
        $textFields = [];
        $fileFields = [];

        // Move the cursor just past the opening delimiter.
        $fill(strlen($boundary) + 8);
        $start = strpos($buf, '--' . $boundary);
        if ($start === false) {
            fclose($stream);
            return;
        }
        $buf = substr($buf, $start + strlen('--' . $boundary));

        while (true) {
            $fill(2);
            if (str_starts_with($buf, '--')) {
                break; // closing delimiter — payload finished
            }
            $buf = preg_replace('/^\r?\n/', '', $buf, 1);

            // ── 2. Part headers ─────────────────────────────────────────────
            while (($headerEnd = strpos($buf, "\r\n\r\n")) === false) {
                $before = strlen($buf);
                $fill($before + $chunkSize);
                if (strlen($buf) === $before) {
                    break 2; // truncated payload
                }
            }

            $rawHeaders = substr($buf, 0, $headerEnd);
            $buf = substr($buf, $headerEnd + 4);

            $disposition = '';
            $fieldMime   = 'application/octet-stream';
            $isBase64    = false;

            foreach (explode("\r\n", $rawHeaders) as $line) {
                if (stripos($line, 'Content-Disposition:') === 0) {
                    $disposition = $line;
                } elseif (stripos($line, 'Content-Type:') === 0) {
                    $fieldMime = trim(substr($line, strlen('Content-Type:')));
                } elseif (stripos($line, 'Content-Transfer-Encoding:') === 0) {
                    $isBase64 = stripos($line, 'base64') !== false;
                }
            }

            $fieldName = null;
            if (preg_match('/name=(?:"([^"]*)"|([^";\r\n]+))/i', $disposition, $nm)) {
                $fieldName = trim(($nm[1] ?? '') !== '' ? $nm[1] : ($nm[2] ?? ''));
            }

            $filename = null;
            if (preg_match('/filename=(?:"([^"]*)"|([^";\r\n]+))/i', $disposition, $fm)) {
                $candidate = trim(($fm[1] ?? '') !== '' ? $fm[1] : ($fm[2] ?? ''));
                if ($candidate !== '') {
                    $filename = $candidate;
                }
            }

            // ── 3. Part body — files stream to disk, fields stay in memory ──
            $tmpPath = null;
            $tmpFp   = null;
            $value   = '';
            $b64rem  = '';

            if ($filename !== null) {
                $tmpPath = tempnam(sys_get_temp_dir(), 'nphp_upl_');
                $tmpFp   = fopen($tmpPath, 'wb');
            }

            $truncated = false;

            while (true) {
                $pos = strpos($buf, $delimiter);
                if ($pos !== false) {
                    $chunk = substr($buf, 0, $pos);
                    if ($tmpFp) {
                        $this->writeBodyData($tmpFp, $chunk, $isBase64, $b64rem, true);
                    } else {
                        $value .= $chunk;
                    }
                    // Everything from the delimiter onwards STAYS in $buf so the
                    // following parts are parsed instead of being discarded.
                    $buf = substr($buf, $pos + $delimLen);
                    break;
                }

                // The delimiter can straddle a chunk edge, so always hold back
                // delimiter-length bytes before flushing what we have.
                if (strlen($buf) > $delimLen) {
                    $flushLen = strlen($buf) - $delimLen;
                    $chunk    = substr($buf, 0, $flushLen);
                    $buf      = substr($buf, $flushLen);
                    if ($tmpFp) {
                        $this->writeBodyData($tmpFp, $chunk, $isBase64, $b64rem, false);
                    } else {
                        $value .= $chunk;
                    }
                }

                $before = strlen($buf);
                $fill($before + $chunkSize);
                if (strlen($buf) === $before) {
                    // EOF with no closing delimiter — keep whatever remains.
                    if ($tmpFp) {
                        $this->writeBodyData($tmpFp, $buf, $isBase64, $b64rem, true);
                    } else {
                        $value .= $buf;
                    }
                    $buf = '';
                    $truncated = true;
                    break;
                }
            }

            if ($fieldName === null || $fieldName === '') {
                if ($tmpFp) {
                    fclose($tmpFp);
                    @unlink($tmpPath);
                }
            } else {
                $isArrayField = str_ends_with($fieldName, '[]');
                $key = $isArrayField ? substr($fieldName, 0, -2) : $fieldName;

                if ($tmpFp) {
                    fclose($tmpFp);
                    $uploaded = new UploadedFile(
                        $tmpPath,
                        $filename,
                        $fieldMime,
                        null,
                        true // test mode = true bypasses is_uploaded_file() validation
                    );
                    if ($isArrayField) {
                        $fileFields[$key][] = $uploaded;
                    } else {
                        $fileFields[$key] = $uploaded;
                    }
                } elseif ($isArrayField) {
                    $textFields[$key][] = $value;
                } else {
                    $textFields[$key] = $value;
                }
            }

            if ($truncated) {
                break;
            }
        }

        fclose($stream);

        foreach ($fileFields as $name => $file) {
            $request->files->set($name, $file);
        }

        if (!empty($textFields)) {
            foreach ($textFields as $key => $val) {
                $_POST[$key] = $val;
            }
            $request->merge($textFields);
        }

        Log::error('[ParseMobileMultipartStream] Parsed multipart body', [
            'url'      => $request->fullUrl(),
            'boundary' => $boundary,
            'fields'   => array_keys($textFields),
            'files'    => array_keys($fileFields),
        ]);
    }

    /**
     * Raw request body handle. Isolated so the parser can be exercised against
     * a fixture stream in tests — php://input is not readable under CLI.
     *
     * @return resource|false
     */
    protected function openInputStream()
    {
        return @fopen('php://input', 'rb');
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
