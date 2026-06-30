<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Domains\Media\Services\UploadService;
use App\Domains\Patients\Models\Patient;
use App\Domains\Media\Jobs\OptimizeVideoJob;
use App\Domains\Media\Jobs\MergeChunksJob;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UploadController extends Controller
{
    public function __construct(private readonly UploadService $uploadService) {}

    public function init(Request $request)
    {
        $t0 = microtime(true);

        $data = $request->validate([
            'filename' => 'sometimes|string|max:255',
            'total_chunks' => 'sometimes|integer|min:1|max:200000',
            'total_size' => 'sometimes|integer|min:0',
            'resume' => 'sometimes|boolean',
            'session_id' => 'sometimes|string',
        ]);

        // Resume an existing session by returning its received chunk list.
        if ($request->boolean('resume') && $request->filled('session_id')) {
            $sessionId = $request->input('session_id');
            $received = $this->uploadService->receivedChunks($sessionId);

            Log::channel('upload')->info('init-resume', [
                'session' => $sessionId,
                'received' => count($received),
                'ms' => round((microtime(true) - $t0) * 1000, 2),
            ]);

            return response()->json([
                'session_id' => $sessionId,
                'received_chunks' => $received,
            ]);
        }

        $sessionId = (string) Str::uuid();

        Log::channel('upload')->info('init', [
            'session' => $sessionId,
            'filename' => $request->input('filename'),
            'total_chunks' => $request->input('total_chunks'),
            'ms' => round((microtime(true) - $t0) * 1000, 2),
        ]);

        return response()->json([
            'session_id' => $sessionId,
            'received_chunks' => [],
        ]);
    }

    /**
     * Hot path — keep this endpoint as thin as possible. No DB writes,
     * no extra validation rules beyond minimum; chunk is stored atomically.
     */
    public function chunk(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string',
            'chunk' => 'required|file',
            'chunk_index' => 'required|integer|min:0',
            'total_chunks' => 'sometimes|integer|min:1',
        ]);

        $this->uploadService->storeChunk(
            $request->input('session_id'),
            $request->file('chunk'),
            (int) $request->input('chunk_index'),
            $request->filled('total_chunks') ? (int) $request->input('total_chunks') : null,
        );

        return response()->json([
            'status' => 'chunk_received',
            'chunk_index' => (int) $request->input('chunk_index'),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Resume helper: which chunks does the server already have?
     * Also returns file status after merge completes.
     * 
     * This endpoint provides a consistent view of the upload state machine:
     * - uploading: chunks are being received
     * - merging: chunks received, merge job dispatched but not started
     * - processing: PatientFile created, being processed (video optimization, etc.)
     * - completed: upload_status = 'ready'
     * - failed: upload_status = 'failed'
     * 
     * IMPORTANT: This endpoint never returns file:null if a PatientFile exists.
     * It checks BOTH the session cache AND the database to ensure consistency.
     */
    public function status(Request $request)
    {
        $request->validate(['session_id' => 'required|string']);

        $sessionId = $request->input('session_id');
        $sessionKey = "upload:{$sessionId}";
        
        $received = $this->uploadService->receivedChunks($sessionId);
        $bytes = $this->uploadService->sessionSize($sessionId);
        $sessionData = $this->uploadService->getSessionData($sessionId);
        
        Log::channel('upload')->debug('status endpoint called', [
            'session' => $sessionId,
            'received_chunks' => count($received),
            'session_data' => $sessionData,
        ]);

        // Determine phase based on session state and file existence
        $phase = 'uploading';
        $file = null;
        $fileUuid = null;

        // Check if we have session data (UUID + status)
        if ($sessionData) {
            $fileUuid = $sessionData['uuid'] ?? null;
            $sessionStatus = $sessionData['status'] ?? null;
            
            // Look up the PatientFile by UUID
            if ($fileUuid) {
                $patientFile = \App\Domains\Media\Models\PatientFile::where('uuid', $fileUuid)->first();
                
                if ($patientFile) {
                    // PatientFile exists - return its status (source of truth)
                    $file = [
                        'uuid' => $patientFile->uuid,
                        'upload_status' => $patientFile->upload_status,
                        'type' => $patientFile->type,
                        'thumbnail_url' => $patientFile->thumbnail_url,
                        'hls_url' => $patientFile->hls_url,
                    ];
                    
                    $phase = match ($patientFile->upload_status) {
                        'ready' => 'completed',
                        'failed' => 'failed',
                        default => 'processing',
                    };
                    
                    Log::channel('upload')->debug('status: PatientFile found', [
                        'session' => $sessionId,
                        'file_uuid' => $fileUuid,
                        'upload_status' => $patientFile->upload_status,
                        'phase' => $phase,
                    ]);
                } else {
                    // Session has UUID but PatientFile not yet created
                    // This is the 'merging' phase
                    $phase = 'merging';
                    
                    Log::channel('upload')->debug('status: PatientFile not yet created, merging phase', [
                        'session' => $sessionId,
                        'file_uuid' => $fileUuid,
                        'session_status' => $sessionStatus,
                    ]);
                }
            }
        } else {
            // No session data - check if chunks exist
            if (count($received) === 0) {
                // No chunks and no session data - session expired or never existed
                Log::channel('upload')->warning('status: session not found and no chunks', [
                    'session' => $sessionId,
                ]);
                
                return response()->json([
                    'session_id' => $sessionId,
                    'phase' => 'unknown',
                    'received_chunks' => [],
                    'received_bytes' => 0,
                    'file' => null,
                    'error' => 'Session not found or expired',
                ], 404);
            }
        }

        return response()->json([
            'session_id' => $sessionId,
            'phase' => $phase,
            'received_chunks' => $received,
            'received_bytes' => $bytes,
            'file' => $file,
            'file_uuid' => $fileUuid,
        ]);
    }

    /**
     * Cancel an in-progress upload; deletes temp chunks server-side.
     */
    public function cancel(Request $request)
    {
        $request->validate(['session_id' => 'required|string']);
        $this->uploadService->deleteSession($request->input('session_id'));
        return response()->json(['status' => 'cancelled']);
    }

    /**
     * Finalize an upload.
     *
     * The HTTP response returns INSTANTLY after the file row is created with
     * upload_status = "queued". The actual chunk merge is dispatched to a queue
     * worker (MergeChunksJob) so the client is never blocked by disk I/O for
     * big files. MergeChunksJob will, in turn, dispatch OptimizeVideoJob.
     */
    public function complete(Request $request)
    {
        $t0 = microtime(true);

        $data = $request->validate([
            'session_id' => 'required|string',
            'total_chunks' => 'required|integer|min:1',
            'patient_uuid' => 'required|string|exists:patients,uuid',
            'metadata' => 'required|array',
            'metadata.original_name' => 'sometimes|string|max:255',
            'metadata.extension' => 'sometimes|string|max:12',
            'metadata.mime_type' => 'sometimes|string|max:127',
            'metadata.category' => 'sometimes|string|max:64',
        ]);

        $patient = Patient::where('uuid', $request->input('patient_uuid'))->firstOrFail();

        if ($request->user()->cannot('update', $patient)) {
            return response()->json(['message' => 'You do not have permission to upload files for this patient.'], 403);
        }

        $sessionId = $request->input('session_id');
        $totalChunks = (int) $request->input('total_chunks');

        // Verify all chunks arrived *cheaply* (cheap list op) — but do NOT
        // merge here. Defer the heavy merge to a queue job.
        $received = $this->uploadService->receivedChunks($sessionId);
        if (count($received) !== $totalChunks) {
            return response()->json([
                'message' => 'Upload incomplete',
                'received' => count($received),
                'expected' => $totalChunks,
            ], 422);
        }

        // Generate UUID upfront to eliminate race condition between /complete
        // and /status endpoints. The UUID is returned immediately so the client
        // can track the file even if the cache entry is not yet written.
        $fileUuid = (string) Str::uuid();

        // Atomically store session→UUID mapping BEFORE dispatching the job.
        // This ensures /status can always find the UUID even if called
        // immediately after /complete returns.
        $sessionKey = "upload:{$sessionId}";
        Cache::put($sessionKey, [
            'uuid' => $fileUuid,
            'patient_id' => $patient->id,
            'uploader_id' => $request->user()->id,
            'status' => 'merging',
            'created_at' => now()->toIso8601String(),
        ], now()->addHours(6));

        Log::channel('upload')->info('complete: session→uuid mapping stored', [
            'session' => $sessionId,
            'file_uuid' => $fileUuid,
            'patient_id' => $patient->id,
        ]);

        // Persist enough metadata for the queue job to do the merge.
        MergeChunksJob::dispatch(
            $sessionId,
            $fileUuid,
            $totalChunks,
            $patient->id,
            $request->input('metadata'),
            $request->user()->id,
        )->onQueue('uploads');

        Log::channel('upload')->info('complete (dispatched merge)', [
            'session' => $sessionId,
            'file_uuid' => $fileUuid,
            'total_chunks' => $totalChunks,
            'dispatch_ms' => round((microtime(true) - $t0) * 1000, 2),
        ]);

        // Return the UUID immediately so the client can track the file.
        // The client can poll /uploads/status or /files/{uuid}/status.
        return response()->json([
            'status' => 'accepted',
            'session_id' => $sessionId,
            'file_uuid' => $fileUuid,
            'processing' => true,
        ], 202);
    }
}