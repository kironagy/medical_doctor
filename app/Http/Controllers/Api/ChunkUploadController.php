<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InitChunkUploadRequest;
use App\Http\Requests\StoreChunkRequest;
use App\Http\Requests\CompleteChunkUploadRequest;
use App\Services\Upload\UploadSessionService;
use App\Services\Upload\ChunkUploadService;
use App\Services\Upload\ChunkMergeService;
use App\Services\Upload\UploadCleanupService;
use App\Services\Upload\UploadValidationService;
use App\Domains\Patients\Models\Patient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MedicalPlus\BackgroundSync\Facades\BackgroundSync;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ChunkUploadController extends Controller
{
    public function __construct(
        private readonly UploadSessionService $sessionService,
        private readonly ChunkUploadService $chunkService,
        private readonly ChunkMergeService $mergeService,
        private readonly UploadCleanupService $cleanupService,
        private readonly UploadValidationService $validationService,
    ) {}

    public function init(InitChunkUploadRequest $request)
    {
        $start = microtime(true);
        $mimeType = $request->input('mime_type');
        $fileName = $request->input('file_name');
        if ($fileName && ($mimeType === 'application/octet-stream' || empty($mimeType))) {
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $correctedMime = match($ext) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'heic' => 'image/heic',
                'heif' => 'image/heif',
                'mp4' => 'video/mp4',
                'mov' => 'video/quicktime',
                'avi' => 'video/x-msvideo',
                'mkv' => 'video/x-matroska',
                'webm' => 'video/webm',
                '3gp' => 'video/3gpp',
                'm4v' => 'video/x-m4v',
                'wmv' => 'video/x-ms-wmv',
                'flv' => 'video/x-flv',
                'pdf' => 'application/pdf',
                default => null,
            };
            if ($correctedMime) {
                $request->merge(['mime_type' => $correctedMime]);
            }
        }

        if (env('UPLOAD_DEBUG')) {
            Log::channel('upload')->info('chunk:init - ENTER Controller', ['payload' => $request->all()]);
        }

        $validated = $request->validated();
        if (env('UPLOAD_DEBUG')) {
            Log::channel('upload')->info('chunk:init - Validation passed');
        }

        try {
            $patient = $this->resolvePatient($request->patient_id);
            Log::channel('upload')->info('chunk:init - Patient resolved', [
                'patient_id' => $patient->id,
                'patient_uuid' => $patient->uuid,
            ]);

            // FIX: On SQLite (NativePHP App), no session user exists. Skip Gate.
            if ($request->user() && $request->user()->cannot('view', $patient)) {
                Log::channel('upload')->warning('chunk:init - Forbidden access to patient', [
                    'user' => $request->user()->id,
                    'patient' => $patient->uuid
                ]);
                return response()->json(['message' => 'Forbidden'], 403);
            }
            if (env('UPLOAD_DEBUG')) {
                Log::channel('upload')->info('chunk:init - Gate check skipped/passed');
            }

            $data = array_merge($request->only(['file_name', 'file_size', 'mime_type']), [
                'patient_id' => $patient->id,
                'patient_uuid' => $patient->uuid,
                'chunk_size' => $request->input('chunk_size', 5 * 1024 * 1024),
                'metadata' => $request->input('metadata'),
            ]);

            $user = $request->user();
            if (!$user && config('database.default') === 'sqlite') {
                $user = \App\Domains\Users\Models\User::first();
                if (!$user) {
                    \App\Domains\Users\Models\User::unguard();
                    $user = \App\Domains\Users\Models\User::firstOrCreate(
                        ['id' => 1],
                        [
                            'name' => 'Default Doctor',
                            'email' => 'doctor@local.test',
                            'password' => bcrypt('password'),
                        ]
                    );
                    \App\Domains\Users\Models\User::reguard();
                }
            }
            $userId = $user?->id ?? $patient->primary_doctor_id ?? $patient->created_by_id ?? 1;
            if (env('UPLOAD_DEBUG')) {
                Log::channel('upload')->info('chunk:init - Resolved user context', ['user_id' => $userId]);
            }

            $session = $this->sessionService->create($data, $userId);
            if (env('UPLOAD_DEBUG')) {
                Log::channel('upload')->info('chunk:init - Upload session created', ['session' => $session->uuid]);
            }

            // Elevates the process's priority for the duration of the upload,
            // same reasoning as RunManualSyncJob. Chunks are still driven by
            // the JS loop in useOfflineUploads.js, so this protects the
            // process from being killed while the app is backgrounded/screen
            // locked — it cannot keep the upload going if the WebView itself
            // is destroyed by fully closing the app, since no more chunk
            // requests get sent once that JS stops running.
            BackgroundSync::start('جاري رفع الملف', $data['file_name']);

            $duration = (microtime(true) - $start) * 1000;

            // Always log the summary line (cheap: no payload, just scalars).
            Log::channel('upload')->info('chunk:init - OK', [
                'session'   => $session->uuid,
                'file'      => $data['file_name'],
                'chunk_sz'  => $session->chunk_size,
                'chunks'    => $session->total_chunks,
            ]);

            return response()->json([
                'upload_id' => $session->uuid,
                'chunk_size' => $session->chunk_size,
                'total_chunks' => $session->total_chunks,
                'total_size' => $session->total_size,
                'expires_at' => $session->expires_at->toIso8601String(),
            ])->header('X-Server-Time', round($duration, 2));
        } catch (HttpException $e) {
            // ── FIX-REL-1: preserve the real status (e.g. 422 unsupported
            // MIME from UploadValidationService::validateInit()) instead of
            // letting the \Throwable catch below force it to 500.
            return response()->json([
                'message' => $e->getMessage(),
                'patient_uuid' => $request->input('patient_id'),
            ])->setStatusCode($e->getStatusCode());
        } catch (\Throwable $e) {
            $sqlState = null;
            $sqliteError = null;
            if ($e instanceof \PDOException) {
                $sqlState = $e->errorInfo[0] ?? $e->getCode();
                $sqliteError = $e->errorInfo[2] ?? $e->getMessage();
            } elseif ($e->getPrevious() instanceof \PDOException) {
                $pdoEx = $e->getPrevious();
                $sqlState = $pdoEx->errorInfo[0] ?? $pdoEx->getCode();
                $sqliteError = $pdoEx->errorInfo[2] ?? $pdoEx->getMessage();
            }

            Log::channel('upload')->error('UPLOAD EXCEPTION (ChunkUploadController@init)', [
                'exception'          => get_class($e),
                'message'            => $e->getMessage(),
                'sqlstate'           => $sqlState,
                'sqlite_error'       => $sqliteError,
                'file'               => $e->getFile(),
                'line'               => $e->getLine(),
                'trace'              => $e->getTraceAsString(),
                'previous_exception' => $e->getPrevious() ? get_class($e->getPrevious()) . ': ' . $e->getPrevious()->getMessage() : null,
                'request_payload'    => $request->all(),
                'authenticated_user' => auth()->id() ?? ($request->user()?->id ?? null),
                'patient_uuid'       => $request->input('patient_id'),
                'storage_disk'       => 'local',
                'filesystem_path'    => isset($session) ? ($session->final_path ?? null) : null,
                'generated_upload_id'=> isset($session) ? ($session->uuid ?? null) : null,
                'data'               => $data ?? null,
            ]);

            return response()->json([
                'message'            => 'Failed to initialize upload: ' . $e->getMessage(),
                'error'              => $e->getMessage(),
                'exception'          => get_class($e),
                'sqlstate'           => $sqlState,
                'sqlite_error'       => $sqliteError,
                'file'               => $e->getFile(),
                'line'               => $e->getLine(),
                'trace'              => $e->getTraceAsString(),
                'previous_exception' => $e->getPrevious() ? $e->getPrevious()->getMessage() : null,
                'patient_uuid'       => $request->input('patient_id'),
                'generated_upload_id'=> isset($session) ? ($session->uuid ?? null) : null,
            ], 500);
        }
    }

    public function chunk(StoreChunkRequest $request)
    {
        $start = microtime(true);
        $validated = $request->validated();

        try {
            $session = $this->sessionService->findOrFail($validated['upload_id']);
            if ($request->user() && !$this->sessionService->ownedByUser($session, $request->user()->id)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $result = $this->chunkService->storeChunk(
                $session,
                $request->file('chunk'),
                (int) $validated['chunk_index'],
                $validated['checksum'] ?? null
            );

            if ($session->total_chunks > 0) {
                $percent = (int) round((((int) $validated['chunk_index'] + 1) / $session->total_chunks) * 100);
                BackgroundSync::progress($session->original_name ?? '', min($percent, 100));
            }

            $duration = (microtime(true) - $start) * 1000;
            return response()->json($result)->header('X-Server-Time', round($duration, 2));
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message'   => 'Upload session not found or expired',
                'upload_id' => $validated['upload_id'] ?? null,
                'code'      => 'SESSION_EXPIRED',
            ], 410);
        } catch (HttpException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'chunk_index' => $validated['chunk_index'] ?? null,
                'upload_id' => $validated['upload_id'] ?? null,
            ])->header('X-Server-Time', round((microtime(true) - $start) * 1000, 2))
            ->setStatusCode($e->getStatusCode());
        } catch (\Throwable $e) {
            if ($this->isSqliteBusy($e)) {
                return response()->json([
                    'message'   => 'Database is busy, please retry',
                    'upload_id' => $validated['upload_id'] ?? null,
                    'code'      => 'DB_BUSY',
                ], 503)->header('Retry-After', 1);
            }

            $sqlState = null;
            $sqliteError = null;
            if ($e instanceof \PDOException) {
                $sqlState = $e->errorInfo[0] ?? $e->getCode();
                $sqliteError = $e->errorInfo[2] ?? $e->getMessage();
            } elseif ($e->getPrevious() instanceof \PDOException) {
                $pdoEx = $e->getPrevious();
                $sqlState = $pdoEx->errorInfo[0] ?? $pdoEx->getCode();
                $sqliteError = $pdoEx->errorInfo[2] ?? $pdoEx->getMessage();
            }

            Log::channel('upload')->error('UPLOAD EXCEPTION (ChunkUploadController@chunk)', [
                'upload_id'    => $validated['upload_id'] ?? null,
                'chunk'        => $validated['chunk_index'] ?? null,
                'exception'    => get_class($e),
                'message'      => $e->getMessage(),
                'sqlstate'     => $sqlState,
                'sqlite_error' => $sqliteError,
                'file'         => $e->getFile(),
                'line'         => $e->getLine(),
                'trace'        => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message'      => 'Chunk upload failed: ' . $e->getMessage(),
                'error'        => $e->getMessage(),
                'exception'    => get_class($e),
                'sqlstate'     => $sqlState,
                'sqlite_error' => $sqliteError,
                'file'         => $e->getFile(),
                'line'         => $e->getLine(),
                'trace'        => $e->getTraceAsString(),
            ], 500);
        }
    }

    public function complete(CompleteChunkUploadRequest $request)
    {
        if ($request->hasSession()) {
            $request->session()->save();
        }
        $start = microtime(true);
        $validated = $request->validated();

        try {
            $session = $this->sessionService->findOrFail($validated['upload_id']);
            if ($request->user() && !$this->sessionService->ownedByUser($session, $request->user()->id)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $patientFile = $this->mergeService->merge($session);

            $duration = (microtime(true) - $start) * 1000;

            BackgroundSync::stop('اكتمل رفع ' . ($session->original_name ?? 'الملف'));

            return response()->json([
                'uuid' => $patientFile->uuid,
                'upload_status' => $patientFile->upload_status,
                'url' => $patientFile->url,
                'thumbnail_url' => $patientFile->thumbnail_url,
                'type' => $patientFile->type,
            ])->header('X-Server-Time', round($duration, 2));
        } catch (ModelNotFoundException $e) {
            // ── FIX-REL-4: only genuine failures fire the failure toast.
            // An expired/unknown session is a recoverable client state, not
            // a crash — do not notify BackgroundSync::stop(failure) for it.
            return response()->json([
                'message'   => 'Upload session not found or expired',
                'upload_id' => $validated['upload_id'] ?? null,
                'code'      => 'SESSION_EXPIRED',
            ], 410);
        } catch (HttpException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'upload_id' => $validated['upload_id'] ?? null,
            ])->header('X-Server-Time', round((microtime(true) - $start) * 1000, 2))
            ->setStatusCode($e->getStatusCode());
        } catch (\Throwable $e) {
            if ($this->isSqliteBusy($e)) {
                BackgroundSync::stop('فشل رفع الملف');
                return response()->json([
                    'message'   => 'Database is busy, please retry',
                    'upload_id' => $validated['upload_id'] ?? null,
                    'code'      => 'DB_BUSY',
                ], 503)->header('Retry-After', 1);
            }

            $sqlState = null;
            $sqliteError = null;
            if ($e instanceof \PDOException) {
                $sqlState = $e->errorInfo[0] ?? $e->getCode();
                $sqliteError = $e->errorInfo[2] ?? $e->getMessage();
            } elseif ($e->getPrevious() instanceof \PDOException) {
                $pdoEx = $e->getPrevious();
                $sqlState = $pdoEx->errorInfo[0] ?? $pdoEx->getCode();
                $sqliteError = $pdoEx->errorInfo[2] ?? $pdoEx->getMessage();
            }

            Log::channel('upload')->error('UPLOAD EXCEPTION (ChunkUploadController@complete)', [
                'upload_id'    => $validated['upload_id'] ?? null,
                'exception'    => get_class($e),
                'message'      => $e->getMessage(),
                'sqlstate'     => $sqlState,
                'sqlite_error' => $sqliteError,
                'file'         => $e->getFile(),
                'line'         => $e->getLine(),
                'trace'        => $e->getTraceAsString(),
            ]);

            // Genuine failure — this is the only path that should fire the
            // BackgroundSync failure notification (previously a `finally`
            // block fired it for recoverable 4xx responses too).
            BackgroundSync::stop('فشل رفع الملف');

            return response()->json([
                'message'      => 'Upload completion failed: ' . $e->getMessage(),
                'error'        => $e->getMessage(),
                'exception'    => get_class($e),
                'sqlstate'     => $sqlState,
                'sqlite_error' => $sqliteError,
                'file'         => $e->getFile(),
                'line'         => $e->getLine(),
                'trace'        => $e->getTraceAsString(),
            ], 500);
        }
    }

    /**
     * SQLITE_BUSY / SQLITE_BUSY_SNAPSHOT surfaces as a PDOException with
     * "database is locked" — DEFERRED transaction mode does not retry that
     * error class via busy_timeout. Detect it so the client gets a 503 with
     * Retry-After instead of an indistinguishable 500.
     */
    private function isSqliteBusy(\Throwable $e): bool
    {
        $pdoEx = $e instanceof \PDOException ? $e : ($e->getPrevious() instanceof \PDOException ? $e->getPrevious() : null);
        if (!$pdoEx) {
            return false;
        }
        $message = $pdoEx->getMessage();
        return str_contains($message, 'database is locked')
            || str_contains($message, 'SQLITE_BUSY');
    }

    public function cancel(Request $request, string $uuid)
    {
        if ($request->hasSession()) {
            $request->session()->save();
        }
        try {
            $session = $this->sessionService->findOrFail($uuid);
            if ($request->user() && !$this->sessionService->ownedByUser($session, $request->user()->id)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $this->chunkService->cancel($session);
            BackgroundSync::stop();
            return response()->json(['message' => 'Upload cancelled']);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message'   => 'Upload session not found or expired',
                'upload_id' => $uuid,
                'code'      => 'SESSION_EXPIRED',
            ], 410);
        } catch (\Throwable $e) {
            Log::channel('upload')->error('chunk:cancel_error', [
                'upload_id' => $uuid,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Cancel failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function status(Request $request, string $uuid)
    {
        if ($request->hasSession()) {
            $request->session()->save();
        }
        try {
            $session = $this->sessionService->findOrFail($uuid);
            if ($request->user() && !$this->sessionService->ownedByUser($session, $request->user()->id)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $status = $this->chunkService->getStatus($session);
            return response()->json($status);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message'   => 'Upload session not found or expired',
                'upload_id' => $uuid,
                'code'      => 'SESSION_EXPIRED',
            ], 410);
        } catch (\Throwable $e) {
            Log::channel('upload')->error('chunk:status_error', [
                'upload_id' => $uuid,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Status check failed',
                'error' => $e->getMessage(),
                'uuid' => $uuid,
            ], 500);
        }
    }

    private function resolvePatient(string|int $patientId): Patient
    {
        Log::channel('upload')->info('chunk:init - resolvePatient starting', ['patient_id' => $patientId]);

        // ═══ SYNC FIX: Look up the patient WITHOUT the DoctorIsolationScope. ═══
        // The scope filters patients by primary_doctor_id / created_by_id for the
        // CURRENT session user. On the embedded app the session user may differ
        // from the doctor who owns the patient (or be absent entirely), which
        // made the scoped query return null for EXISTING patients — causing a
        // stub to be created for a patient that already exists locally.
        //
        // ═══ SYNC FIX: NEVER overwrite an existing patient with stub data. ═══
        // The previous implementation used updateOrCreate() with unguarded stub
        // data that included 'sync_status' => 'pending_sync'. When the patient
        // existed (e.g. created offline as 'pending_create'), this REWROTE the
        // sync_status to 'pending_sync' — a status the SyncEngine never queries
        // (it only picks up 'pending_create'/'pending_update'). Result: the
        // patient stopped syncing to production and its files waited forever.
        $hasRemoteUuid = \Illuminate\Support\Facades\Schema::hasColumn('patients', 'remote_uuid');
        $patient = is_numeric($patientId)
            ? Patient::withoutGlobalScopes()->find((int) $patientId)
            : Patient::withoutGlobalScopes()->where(function ($q) use ($patientId, $hasRemoteUuid) {
                $q->where('uuid', $patientId);
                if ($hasRemoteUuid) {
                    $q->orWhere('remote_uuid', $patientId);
                }
            })->first();

        if ($patient) {
            Log::channel('upload')->info('chunk:init - resolvePatient patient found locally');
            return $patient;
        }

        // FIX-ARCH-5: Gate stub patient creation on embedded SQLite database. On MySQL (production), return 404.
        if (config('database.default') !== 'sqlite') {
            throw new HttpException(404, 'Patient not found');
        }

        Log::channel('upload')->info('chunk:init - resolvePatient creating stub for local patient');
        $uuid = is_numeric($patientId) ? (string) \Illuminate\Support\Str::uuid() : $patientId;

        $stubData = [
            'uuid' => $uuid,
            // ═══ SYNC FIX: 'pending_create' (NOT 'pending_sync') ═══════════
            // SyncEngineService::syncPendingPatients() queries patients with
            // sync_status IN ('pending_create', 'pending_update'). Stubs created
            // with 'pending_sync' were invisible to the sync engine and never
            // reached the production server.
            'sync_status' => 'pending_create',
            'name' => 'Patient ' . $uuid,
        ];

        $userId = auth()->id();
        if (!$userId && config('database.default') === 'sqlite') {
            $user = \App\Domains\Users\Models\User::first();
            if (!$user) {
                \App\Domains\Users\Models\User::unguard();
                $user = \App\Domains\Users\Models\User::firstOrCreate(
                    ['id' => 1],
                    [
                        'name' => 'Default Doctor',
                        'email' => 'doctor@local.test',
                        'password' => bcrypt('password'),
                    ]
                );
                \App\Domains\Users\Models\User::reguard();
            }
            $userId = $user->id;
        }

        if ($userId) {
            $stubData['primary_doctor_id'] = $userId;
            $stubData['created_by_id'] = $userId;
        }

        // Insert-only (never update an existing patient here — see comment above).
        $patient = Patient::withoutGlobalScopes()->firstOrCreate(
            ['uuid' => $uuid],
            $stubData
        );

        return $patient;
    }
}
