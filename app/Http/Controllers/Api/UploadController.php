<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Domains\Media\Services\UploadService;
use App\Domains\Patients\Models\Patient;
use App\Domains\Media\Jobs\OptimizeVideoJob;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class UploadController extends Controller
{
    public function __construct(private readonly UploadService $uploadService) {}

    public function init(Request $request)
    {
        $t0 = microtime(true);

        $request->validate([
            'filename' => 'sometimes|string|max:255',
            'total_chunks' => 'sometimes|integer|min:1',
            'resume' => 'sometimes|boolean',
        ]);

        $sessionId = (string) Str::uuid();

        // Resume support: client may pass an existing session_id to recover an
        // interrupted upload and learn which chunks are already on disk.
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

        Log::channel('upload')->info('init', [
            'session' => $sessionId,
            'filename' => $request->input('filename'),
            'ms' => round((microtime(true) - $t0) * 1000, 2),
        ]);

        return response()->json([
            'session_id' => $sessionId,
            'received_chunks' => [],
        ]);
    }

    public function chunk(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string',
            'chunk' => 'required|file',
            'chunk_index' => 'required|integer|min:0',
        ]);

        $this->uploadService->storeChunk(
            $request->session_id,
            $request->file('chunk'),
            (int) $request->chunk_index
        );

        return response()->json([
            'status' => 'chunk_received',
            'chunk_index' => (int) $request->chunk_index,
        ]);
    }

    /**
     * Returns already-received chunk indexes for a session (used by the client
     * to resume an interrupted upload after a refresh/crash).
     */
    public function status(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string',
        ]);

        $received = $this->uploadService->receivedChunks($request->input('session_id'));

        return response()->json([
            'session_id' => $request->input('session_id'),
            'received_chunks' => $received,
        ]);
    }

    public function complete(Request $request)
    {
        $t0 = microtime(true);

        $request->validate([
            'session_id' => 'required|string',
            'total_chunks' => 'required|integer|min:1',
            'patient_uuid' => 'required|string|exists:patients,uuid',
            'metadata' => 'required|array',
        ]);

        $patient = Patient::where('uuid', $request->patient_uuid)->firstOrFail();

        if ($request->user()->cannot('update', $patient)) {
            return response()->json(['message' => 'You do not have permission to upload files for this patient.'], 403);
        }

        $patientFile = $this->uploadService->mergeChunks(
            $request->session_id,
            $request->total_chunks,
            $patient,
            $request->metadata,
            $request->user()->id
        );

        if ($patientFile->type === 'video') {
            OptimizeVideoJob::dispatch($patientFile)->onQueue('video');
        } else {
            $patientFile->update(['upload_status' => 'ready']);
        }

        Log::channel('upload')->info('complete', [
            'session' => $request->session_id,
            'file_uuid' => $patientFile->uuid,
            'total_ms' => round((microtime(true) - $t0) * 1000, 2),
        ]);

        return response()->json([
            'status' => 'success',
            'file' => [
                'uuid' => $patientFile->uuid,
                'status' => $patientFile->upload_status,
            ],
        ]);
    }
}