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
     */
    public function status(Request $request)
    {
        $request->validate(['session_id' => 'required|string']);

        $sessionId = $request->input('session_id');
        $received = $this->uploadService->receivedChunks($sessionId);
        $bytes = $this->uploadService->sessionSize($sessionId);
        $file = $this->uploadService->sessionFile($sessionId);

        // When chunks exist on disk, the session is still uploading.
        // When no chunks remain AND no file row yet, merge is in flight.
        // When a file row exists, merge is complete (and the file may be
        // processing → ready). The client uses this to drive its state machine.
        $phase = 'uploading';
        if (count($received) === 0) {
            $phase = $file ? 'processing' : 'merging';
        }

        if ($file) {
            $phase = match ($file['upload_status']) {
                'ready' => 'completed',
                'failed' => 'failed',
                default => 'processing',
            };
        }

        return response()->json([
            'session_id' => $sessionId,
            'phase' => $phase,
            'received_chunks' => $received,
            'received_bytes' => $bytes,
            'file' => $file,
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

        // Persist enough metadata for the queue job to do the merge.
        MergeChunksJob::dispatch(
            $sessionId,
            $totalChunks,
            $patient->id,
            $request->input('metadata'),
            $request->user()->id,
        )->onQueue('uploads');

        Log::channel('upload')->info('complete (dispatched merge)', [
            'session' => $sessionId,
            'total_chunks' => $totalChunks,
            'dispatch_ms' => round((microtime(true) - $t0) * 1000, 2),
        ]);

        // Don't surface a synthetic uuid yet — client polls the merge status
        // via /uploads/status (received array collapses) if it wants; the file
        // row appears via normal props reload once MergeChunksJob finishes.
        return response()->json([
            'status' => 'accepted',
            'session_id' => $sessionId,
            // hint that processing is async:
            'processing' => true,
        ], 202);
    }
}