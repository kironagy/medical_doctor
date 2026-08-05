<?php

namespace App\Http\Controllers\Diag;

/**
 * ⚠️ TEMPORARY DIAGNOSTIC CONTROLLER — DELETE AFTER PHASE 0 ⚠️
 *
 * Serves the *same* file through four different transports so we can observe,
 * on a real device, which one the NativePHP WebView actually renders:
 *
 *   stream  — StreamedResponse + manual echo/fread loop (what the app does today)
 *   binary  — BinaryFileResponse via response()->file()
 *   static  — a plain static file under public/ (bypasses PHP entirely)
 *   base64  — data: URI in JSON (the current fallback)
 *
 * The blade page loads all four into <img>/<video> tags and POSTs the outcome
 * back to /_native/diag/report, which writes it to laravel.log so the results
 * can be read off-device with `adb shell run-as … cat …/laravel.log`.
 */

use App\Domains\Auth\Scopes\DoctorIsolationScope;
use App\Domains\Media\Models\PatientFile;
use App\Http\Controllers\Controller;
use App\Services\Mobile\FileCacheService;
use App\Services\OfflineUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaTransportDiagController extends Controller
{
    private const STATIC_DIR = 'diag-media';

    public function __construct(
        private readonly FileCacheService $fileCache,
        private readonly OfflineUploadService $offlineUploads,
    ) {}

    /**
     * Landing page: pick a candidate image + video to test.
     */
    public function index()
    {
        $files = PatientFile::withoutGlobalScope(DoctorIsolationScope::class)
            ->orderByDesc('id')
            ->limit(60)
            ->get();

        $rows = $files->map(function (PatientFile $f) {
            $abs = $this->resolveAbsolutePath($f);
            return [
                'uuid'        => $f->uuid,
                'remote_uuid' => $f->remote_uuid,
                'file_name'   => $f->file_name,
                'mime_type'   => $f->mime_type,
                'size'        => $f->size,
                'file_path'   => $f->file_path,
                'sync_status' => $f->sync_status,
                'source'      => $this->resolveSource($f),
                'on_disk'     => $abs !== null,
                'real_size'   => $abs ? @filesize($abs) : null,
            ];
        })->values()->all();

        Log::info('[DIAG] index rendered', ['count' => count($rows)]);

        return view('diag.media-index', ['rows' => $rows]);
    }

    /**
     * Test page for a single file — loads all four transports at once.
     */
    public function show(string $uuid)
    {
        $file = $this->findFile($uuid);
        if (!$file) {
            abort(404, 'No PatientFile row for ' . $uuid);
        }

        $abs = $this->resolveAbsolutePath($file);
        $staticUrl = $abs ? $this->publishStatic($file, $abs) : null;

        Log::info('[DIAG] show', [
            'uuid'       => $uuid,
            'source'     => $this->resolveSource($file),
            'abs'        => $abs,
            'abs_exists' => $abs ? file_exists($abs) : false,
            'size'       => $abs ? @filesize($abs) : null,
            'static_url' => $staticUrl,
        ]);

        return view('diag.media-test', [
            'file'      => $file,
            'abs'       => $abs,
            'realSize'  => $abs ? @filesize($abs) : null,
            'source'    => $this->resolveSource($file),
            'staticUrl' => $staticUrl,
        ]);
    }

    /**
     * Serve the bytes through the requested transport.
     */
    public function serve(Request $request, string $uuid, string $mode)
    {
        $file = $this->findFile($uuid);
        if (!$file) {
            abort(404, 'No PatientFile row.');
        }

        $abs = $this->resolveAbsolutePath($file);
        if (!$abs) {
            abort(404, 'No bytes on disk for this file.');
        }

        $mime = $file->mime_type ?: (mime_content_type($abs) ?: 'application/octet-stream');
        $size = filesize($abs);

        Log::info('[DIAG] serve', [
            'uuid'  => $uuid,
            'mode'  => $mode,
            'range' => $request->header('Range'),
            'size'  => $size,
            'mime'  => $mime,
        ]);

        return match ($mode) {
            'stream' => $this->serveStreamed($request, $abs, $mime, $file->file_name, $size),
            'binary' => response()->file($abs, [
                'Content-Type'        => $mime,
                'Content-Disposition' => 'inline; filename="' . $file->file_name . '"',
                'Cache-Control'       => 'private, no-transform, max-age=3600',
            ]),
            'base64' => response()->json([
                'mime' => $mime,
                'size' => $size,
                'data' => base64_encode(file_get_contents($abs)),
            ]),
            default  => abort(400, 'Unknown mode: ' . $mode),
        };
    }

    /**
     * Collect the on-device outcome of each transport into laravel.log.
     */
    public function report(Request $request)
    {
        Log::info('[DIAG][RESULT] ' . json_encode($request->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return response()->json(['ok' => true]);
    }

    // ---------------------------------------------------------------

    /**
     * Byte-for-byte replica of FileAccessController::streamDirect()'s no-Range
     * path — the transport we suspect the embedded runtime drops.
     */
    private function serveStreamed(Request $request, string $abs, string $mime, ?string $name, int $size)
    {
        $headers = [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="' . $name . '"',
            'Accept-Ranges'       => 'bytes',
            'Cache-Control'       => 'private, no-transform, max-age=3600',
            'Content-Length'      => (string) $size,
        ];

        @ini_set('output_handler', '');
        @ini_set('zlib.output_compression', 0);
        while (ob_get_level()) {
            ob_end_clean();
        }

        $fp = fopen($abs, 'rb');

        return new StreamedResponse(function () use ($fp) {
            $buf = 1024 * 1024;
            while (!feof($fp)) {
                echo fread($fp, $buf);
                fflush($fp);
            }
            fclose($fp);
        }, 200, $headers);
    }

    /**
     * Hardlink (or copy) the file into public/ so the WebView can fetch it as
     * an ordinary static asset with no PHP involvement.
     */
    private function publishStatic(PatientFile $file, string $abs): ?string
    {
        $ext = pathinfo($file->file_name ?: $abs, PATHINFO_EXTENSION) ?: 'bin';
        $dir = public_path(self::STATIC_DIR);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            Log::warning('[DIAG] could not create public static dir', ['dir' => $dir]);
            return null;
        }

        $target = $dir . '/' . $file->uuid . '.' . $ext;

        if (!file_exists($target)) {
            // Hardlink costs zero bytes on the same filesystem; fall back to copy.
            if (!@link($abs, $target) && !@copy($abs, $target)) {
                Log::warning('[DIAG] could not publish static copy', ['from' => $abs, 'to' => $target]);
                return null;
            }
        }

        return '/' . self::STATIC_DIR . '/' . $file->uuid . '.' . $ext;
    }

    private function findFile(string $uuid): ?PatientFile
    {
        return PatientFile::withoutGlobalScope(DoctorIsolationScope::class)
            ->where(fn ($q) => $q->where('uuid', $uuid)->orWhere('remote_uuid', $uuid))
            ->first();
    }

    /**
     * Where the bytes actually live: the app's own uploads sit on the `local`
     * disk, website downloads land in the file_cache dir, and pending offline
     * uploads live in the offline_files dir.
     */
    private function resolveAbsolutePath(PatientFile $file): ?string
    {
        if ($file->file_path) {
            $abs = Storage::disk('local')->path($file->file_path);
            if (is_file($abs)) {
                return $abs;
            }
        }

        foreach (array_filter([$file->uuid, $file->remote_uuid]) as $key) {
            $entry = DB::table('file_cache')->where('file_uuid', $key)->first();
            if ($entry) {
                $abs = $this->fileCache->resolvePath($entry->local_path);
                if (is_file($abs)) {
                    return $abs;
                }
            }

            $offline = DB::table('offline_files')->where('uuid', $key)->first();
            if ($offline) {
                $abs = $this->offlineUploads->absolutePath($offline->local_path);
                if (is_file($abs)) {
                    return $abs;
                }
            }
        }

        return null;
    }

    private function resolveSource(PatientFile $file): string
    {
        if ($file->file_path && is_file(Storage::disk('local')->path($file->file_path))) {
            return 'local-disk';
        }
        foreach (array_filter([$file->uuid, $file->remote_uuid]) as $key) {
            if (DB::table('file_cache')->where('file_uuid', $key)->exists()) {
                return 'file-cache';
            }
            if (DB::table('offline_files')->where('uuid', $key)->exists()) {
                return 'offline-files';
            }
        }

        return 'missing';
    }
}
