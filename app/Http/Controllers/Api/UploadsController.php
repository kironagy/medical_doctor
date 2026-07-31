<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\Api\ApiPatientRepository;
use App\Services\Upload\UploadSessionService;
use App\Services\Upload\ChunkUploadService;
use App\Services\Upload\ChunkMergeService;
use App\Services\Upload\UploadCleanupService;
use App\Services\Upload\UploadValidationService;
use App\Domains\Patients\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UploadsController extends Controller
{
    public function __construct(
        private readonly UploadSessionService $sessionService,
        private readonly ChunkUploadService $chunkService,
        private readonly ChunkMergeService $mergeService,
        private readonly UploadCleanupService $cleanupService,
        private readonly UploadValidationService $validationService,
    ) {}

    public function start(Request $request)
    {
        $start = microtime(true);
        Log::channel('upload')->info('upload:start - ENTER Controller', [
            'payload' => $request->all()
        ]);

        try {
            $validated = $request->validate([
                'file_name' => 'required|string|max:255',
                'file_size' => 'required|integer|min:1|max:5368709120',
                'mime_type' => 'required|string|max:255',
                'patient_id' => 'required',
                'chunk_size' => 'sometimes|integer|min:1048576|max:52428800',
                'metadata' => 'sometimes|array',
                'metadata.title' => 'sometimes|nullable|string|max:255',
                'metadata.desc' => 'sometimes|nullable|string|max:1000',
                'metadata.category' => 'sometimes|nullable|string|max:100',
                'metadata.date' => 'sometimes|nullable|date',
            ]);
            Log::channel('upload')->info('upload:start - Validation passed');

            $patient = $this->resolvePatient($request->patient_id);
            Log::channel('upload')->info('upload:start - Patient resolved', [
                'patient_id' => $patient->id,
                'patient_uuid' => $patient->uuid,
            ]);

            // FIX: On SQLite (NativePHP App), there is no session-authenticated user.
            // Skip Gate authorization — the local SQLite DB is trusted.
            if ($request->user() && $request->user()->cannot('view', $patient)) {
                Log::channel('upload')->warning('upload:start - Forbidden access to patient', [
                    'user' => $request->user()->id,
                    'patient' => $patient->uuid
                ]);
                return response()->json(['message' => 'Forbidden'], 403);
            }
            Log::channel('upload')->info('upload:start - Gate check skipped/passed');

            $data = array_merge($request->only(['file_name', 'file_size', 'mime_type']), [
                'patient_id' => $patient->id,
                'patient_uuid' => $patient->uuid,
                'chunk_size' => $request->input('chunk_size', 5 * 1024 * 1024),
                'metadata' => $request->input('metadata'),
            ]);

            $userId = $request->user()?->id ?? $patient->primary_doctor_id ?? $patient->created_by_id ?? \App\Domains\Users\Models\User::value('id') ?? 1;
            Log::channel('upload')->info('upload:start - Resolved user context', [
                'user_id' => $userId
            ]);

            $session = $this->sessionService->create($data, $userId);
            Log::channel('upload')->info('upload:start - Upload session created', [
                'session' => $session->uuid
            ]);

            $duration = (microtime(true) - $start) * 1000;

            Log::channel('upload')->info('upload:start - Returning response', [
                'session'   => $session->uuid,
                'user'      => $userId,
                'patient'   => $patient->id,
                'file'      => $data['file_name'],
                'size'      => $data['file_size'],
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

            Log::channel('upload')->error('UPLOAD EXCEPTION (UploadsController@start)', [
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
                'message'            => 'Failed to start upload: ' . $e->getMessage(),
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

    public function chunk(Request $request)
    {
        $start = microtime(true);

        $validated = $request->validate([
            'upload_id' => 'required|string|size:36',
            'chunk_index' => 'required|integer|min:0',
            'chunk' => 'required|file|max:51200',
            'checksum' => 'sometimes|string|size:64',
        ]);

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

            $duration = (microtime(true) - $start) * 1000;
            return response()->json($result)->header('X-Server-Time', round($duration, 2));
        } catch (HttpException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'chunk_index' => $validated['chunk_index'] ?? null,
                'upload_id' => $validated['upload_id'] ?? null,
            ])->header('X-Server-Time', round((microtime(true) - $start) * 1000, 2))
            ->setStatusCode($e->getStatusCode());
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

            Log::channel('upload')->error('UPLOAD EXCEPTION (UploadsController@chunk)', [
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

    public function status(Request $request, string $id)
    {
        try {
            $session = $this->sessionService->findOrFail($id);
            if ($request->user() && !$this->sessionService->ownedByUser($session, $request->user()->id)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $status = $this->chunkService->getStatus($session);
            return response()->json($status);
        } catch (\Throwable $e) {
            Log::channel('upload')->error('upload:status_error', [
                'upload_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Status check failed',
                'error' => $e->getMessage(),
                'uuid' => $id,
            ], 500);
        }
    }

    public function resume(Request $request, string $id)
    {
        try {
            $session = $this->sessionService->findOrFail($id);
            if ($request->user() && !$this->sessionService->ownedByUser($session, $request->user()->id)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            // Ensure session is in uploading state
            $this->sessionService->ensureUploading($session);
            $status = $this->chunkService->getStatus($session);
            return response()->json($status);
        } catch (\Throwable $e) {
            Log::channel('upload')->error('upload:resume_error', [
                'upload_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Resume failed',
                'error' => $e->getMessage(),
                'uuid' => $id,
            ], 500);
        }
    }

    public function finish(Request $request)
    {
        $start = microtime(true);

        $validated = $request->validate([
            'upload_id' => 'required|string|size:36',
        ]);

        try {
            $session = $this->sessionService->findOrFail($validated['upload_id']);
            if ($request->user() && !$this->sessionService->ownedByUser($session, $request->user()->id)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $patientFile = $this->mergeService->merge($session);

            $duration = (microtime(true) - $start) * 1000;

            return response()->json([
                'uuid' => $patientFile->uuid,
                'upload_status' => $patientFile->upload_status,
                'url' => $patientFile->url,
                'thumbnail_url' => $patientFile->thumbnail_url,
                'type' => $patientFile->type,
            ])->header('X-Server-Time', round($duration, 2));
        } catch (HttpException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'upload_id' => $validated['upload_id'] ?? null,
            ])->header('X-Server-Time', round((microtime(true) - $start) * 1000, 2))
            ->setStatusCode($e->getStatusCode());
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

            Log::channel('upload')->error('UPLOAD EXCEPTION (UploadsController@finish)', [
                'upload_id'    => $validated['upload_id'] ?? null,
                'exception'    => get_class($e),
                'message'      => $e->getMessage(),
                'sqlstate'     => $sqlState,
                'sqlite_error' => $sqliteError,
                'file'         => $e->getFile(),
                'line'         => $e->getLine(),
                'trace'        => $e->getTraceAsString(),
            ]);

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

    public function destroy(Request $request, string $id)
    {
        try {
            $session = $this->sessionService->findOrFail($id);
            if ($request->user() && !$this->sessionService->ownedByUser($session, $request->user()->id)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $this->chunkService->cancel($session);
            return response()->json(['message' => 'Upload cancelled']);
        } catch (\Throwable $e) {
            Log::channel('upload')->error('upload:destroy_error', [
                'upload_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Cancel failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function resolvePatient(string|int $patientId): Patient
    {
        Log::channel('upload')->info('upload:start - resolvePatient starting', ['patient_id' => $patientId]);

        $patient = is_numeric($patientId)
            ? Patient::find((int) $patientId)
            : Patient::where('uuid', $patientId)->first();

        if ($patient) {
            Log::channel('upload')->info('upload:start - resolvePatient patient found locally');
            return $patient;
        }

        try {
            Log::channel('upload')->info('upload:start - resolvePatient patient not found locally, trying API fallback');
            $uuid = is_numeric($patientId) ? null : $patientId;
            $apiPatient = app(ApiPatientRepository::class)->find($uuid ?? $patientId);

            if ($apiPatient) {
                $clean = collect($apiPatient)->only([
                    'uuid', 'date_of_birth',
                    'gender', 'phone', 'email', 'address', 'notes',
                ])->toArray();
                
                $clean['name'] = trim(($apiPatient['first_name'] ?? '') . ' ' . ($apiPatient['last_name'] ?? ''));

                Patient::unguard();
                $patient = Patient::updateOrCreate(['uuid' => $apiPatient['uuid']], $clean);
                Patient::reguard();

                if ($patient) {
                    Log::channel('upload')->info('upload:start - resolvePatient resolved from API successfully');
                    return $patient;
                }
            }
        } catch (\Throwable $e) {
            Log::channel('upload')->warning('resolvePatient API fallback failed', [
                'patient_id' => $patientId,
                'error' => $e->getMessage(),
            ]);
        }

        Log::channel('upload')->info('upload:start - resolvePatient API fallback returned null/failed, creating local stub patient');

        // ── SEC-003 & SQLite NOT NULL constraint FIX: When creating a stub patient,
        // set primary_doctor_id and created_by_id from the authenticated user
        // or default to the first user/doctor (ID 1) if running on SQLite database.
        $stubData = [
            'uuid' => $patientId,
            'sync_status' => 'pending_sync',
            'name' => 'Patient ' . $patientId,
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

        Patient::unguard();
        $patient = Patient::updateOrCreate(
            ['uuid' => $patientId],
            $stubData
        );
        Patient::reguard();

        Log::channel('upload')->info('upload:start - resolvePatient local stub patient created', [
            'patient_id' => $patient->id,
            'patient_uuid' => $patient->uuid,
            'primary_doctor_id' => $patient->primary_doctor_id ?? null,
        ]);

        return $patient;
    }
}
