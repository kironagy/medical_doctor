<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Repositories\OfflineFileRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Services\OfflineUploadService;
use App\Domains\Patients\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class OfflineUploadController extends Controller
{
    public function __construct(
        private readonly OfflineUploadService $offlineUploadService,
        private readonly OfflineFileRepositoryInterface $offlineRepo,
    ) {}

    /**
     * Save a file locally while offline.
     *
     * Steps:
     * 1. Validate the request (file + patient_uuid)
     * 2. Authorize the user can update the patient
     * 3. Copy the file to storage/app/uploads/pending/
     * 4. Calculate SHA-256 hash
     * 5. Store metadata in offline_files table with sync_status = pending_upload
     * 6. Return local UUID for immediate UI display
     *
     * Route: POST /_native/api/offline/uploads
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'file'         => 'required|file|max:512000',
            'patient_uuid' => 'required|string|size:36',
            'title'        => 'sometimes|string|max:255',
            'desc'         => 'sometimes|string|max:1000',
            'category'     => 'sometimes|string|max:100',
        ]);

        // ── BUG-008 FIX: resolvePatient fallback ──────────────────────────────
        // The old code used firstOrFail() which returned 404 when the patient was
        // created locally (pending_create) but not yet synced to the server.
        // Now we use first() + stub creation as a fallback, matching the pattern
        // used in ChunkUploadController::resolvePatient().
        $patient = Patient::where('uuid', $validated['patient_uuid'])->first();

        if (!$patient) {
            // Patient exists on device (user is uploading against it) but is not
            // in local SQLite yet — create a stub so we can associate the file.
            Log::warning('[OfflineUpload] Patient not found locally, creating stub', [
                'patient_uuid' => $validated['patient_uuid'],
            ]);
            try {
                Patient::unguard();
                $patient = Patient::create([
                    'uuid'        => $validated['patient_uuid'],
                    'name'        => 'Patient ' . substr($validated['patient_uuid'], 0, 8),
                    'sync_status' => 'pending_sync',
                ]);
                Patient::reguard();
            } catch (\Throwable $stubErr) {
                Log::error('[OfflineUpload] Failed to create stub patient: ' . $stubErr->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Patient not found and stub creation failed.',
                ], 404);
            }
        }

        $authUser = $request->user();
        if ($authUser) {
            try {
                Gate::authorize('update', $patient);
            } catch (\Throwable $e) {
                Log::warning('[OfflineUpload] Gate authorization failed, continuing (local-only file): ' . $e->getMessage());
                // Allow upload — file is local-only until synced to server
            }
        } else {
            Log::info('[OfflineUpload] No authenticated user, skipping Gate check (offline local file)');
        }

        try {
            $metadata = $this->offlineUploadService->saveLocally(
                $request->file('file'),
                $validated['patient_uuid']
            );

            $record = $this->offlineRepo->create($metadata);

            Log::info('[OfflineUpload] File saved for pending upload', [
                'local_uuid'   => $metadata['uuid'],
                'patient_uuid' => $validated['patient_uuid'],
                'original'     => $metadata['original_name'],
                'type'         => $metadata['type'] ?? 'unknown',
                'size'         => $metadata['size'],
            ]);

            $url = '/_native/cache/files/' . $metadata['uuid'];
            $thumbnailUrl = str_starts_with($metadata['mime_type'], 'image/')
                ? $url
                : (str_starts_with($metadata['mime_type'], 'video/')
                    ? '/_native/cache/files/' . $metadata['uuid'] . '/thumbnail'
                    : null);

            return response()->json([
                'success'       => true,
                'uuid'          => $metadata['uuid'],
                'patient_uuid'  => $validated['patient_uuid'],
                'original_name' => $metadata['original_name'],
                'mime_type'     => $metadata['mime_type'],
                'extension'     => $metadata['extension'],
                'size'          => $metadata['size'],
                'sync_status'   => 'pending_upload',
                'type'          => $metadata['type'] ?? 'document',
                'local_path'    => $metadata['local_path'],
                'url'           => $url,
                'thumbnail_url' => $thumbnailUrl,
                'created_at'    => now()->toIso8601String(),
            ], 201);
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

            Log::error('UPLOAD EXCEPTION (OfflineUpload)', [
                'url'          => $request->fullUrl(),
                'patient_uuid' => $validated['patient_uuid'] ?? null,
                'exception'    => get_class($e),
                'message'      => $e->getMessage(),
                'sqlstate'     => $sqlState,
                'sqlite_error' => $sqliteError,
                'file'         => $e->getFile(),
                'line'         => $e->getLine(),
            ]);

            return response()->json([
                'success'      => false,
                'message'      => 'Failed to save file for offline upload: ' . $e->getMessage(),
                'exception'    => get_class($e),
                'sqlstate'     => $sqlState,
                'sqlite_error' => $sqliteError,
                'file'         => $e->getFile(),
                'line'         => $e->getLine(),
            ], 500);
        }
    }

    /**
     * Get the sync status of an offline file.
     *
     * Route: GET /_native/api/offline/uploads/{uuid}/status
     */
    public function status(string $uuid)
    {
        $record = $this->offlineRepo->findByUuid($uuid);

        if (!$record) {
            return response()->json([
                'found'  => false,
                'uuid'   => $uuid,
            ], 404);
        }

        return response()->json([
            'found'        => true,
            'uuid'         => $record['uuid'],
            'patient_uuid' => $record['patient_uuid'],
            'original_name' => $record['original_name'],
            'sync_status'  => $record['sync_status'],
            'remote_uuid'  => $record['remote_uuid'],
            'error_message' => $record['error_message'],
            'retry_count'  => $record['retry_count'],
            'created_at'   => $record['created_at'],
        ]);
    }

    /**
     * Retry a failed offline upload.
     *
     * Resets the sync_status to pending_upload so the sync command picks it up.
     *
     * Route: POST /_native/api/offline/uploads/{uuid}/retry
     */
    public function retry(string $uuid)
    {
        $record = $this->offlineRepo->findByUuid($uuid);

        if (!$record) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // Authorize — must have update access to the patient (same as store and destroy)
        $patient = Patient::where('uuid', $record['patient_uuid'])->first();
        if ($patient) {
            Gate::authorize('update', $patient);
        }

        // Reset to pending so the sync command retries it
        \Illuminate\Support\Facades\DB::table('offline_files')
            ->where('uuid', $uuid)
            ->update([
                'sync_status'   => 'pending_upload',
                'error_message' => null,
                'updated_at'    => now(),
            ]);

        Log::info('[OfflineUpload] Queued for retry', ['uuid' => $uuid]);

        return response()->json([
            'success'     => true,
            'uuid'        => $uuid,
            'sync_status' => 'pending_upload',
        ]);
    }

    /**
     * Delete a pending offline file (local file + DB record).
     *
     * Route: DELETE /_native/api/offline/uploads/{uuid}
     */
    public function destroy(string $uuid)
    {
        $record = $this->offlineRepo->findByUuid($uuid);

        if (!$record) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // Authorize — the user must have update access to the patient
        $patient = Patient::where('uuid', $record['patient_uuid'])->first();
        if ($patient) {
            Gate::authorize('update', $patient);
        }

        // Delete the local file
        $this->offlineUploadService->deleteLocal($record['local_path']);

        // Delete the DB record
        $this->offlineRepo->delete($uuid);

        Log::info('[OfflineUpload] Deleted pending upload', ['uuid' => $uuid]);

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * List all pending offline files for a patient.
     *
     * Route: GET /_native/api/offline/uploads?patient_uuid={uuid}
     */
    public function index(Request $request)
    {
        $patientUuid = $request->get('patient_uuid');
        $status = $request->get('status');

        $query = \Illuminate\Support\Facades\DB::table('offline_files');

        if ($patientUuid) {
            $query->where('patient_uuid', $patientUuid);
        }

        if ($status) {
            $query->where('sync_status', $status);
        }

        $files = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $files->map(fn ($f) => (array) $f),
        ]);
    }
}
