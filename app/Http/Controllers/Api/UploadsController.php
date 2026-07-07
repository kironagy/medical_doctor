<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        $patient = is_numeric($request->patient_id)
            ? Patient::findOrFail((int) $request->patient_id)
            : Patient::where('uuid', $request->patient_id)->firstOrFail();

        if ($request->user()->cannot('view', $patient)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = array_merge($request->only(['file_name', 'file_size', 'mime_type']), [
            'patient_id' => $patient->id,
            'patient_uuid' => $patient->uuid,
            'chunk_size' => $request->input('chunk_size', 5 * 1024 * 1024),
            'metadata' => $request->input('metadata'),
        ]);

        try {
            $session = $this->sessionService->create($data, $request->user()->id);

            $duration = (microtime(true) - $start) * 1000;

            Log::channel('upload')->info('upload:start', [
                'session'   => $session->uuid,
                'user'      => $request->user()->id,
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
            Log::channel('upload')->error('upload:start_error', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            return response()->json([
                'message' => 'Failed to start upload',
                'error' => $e->getMessage(),
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
            if (!$this->sessionService->ownedByUser($session, $request->user()->id)) {
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
            Log::channel('upload')->error('upload:chunk_error', [
                'upload_id' => $validated['upload_id'] ?? null,
                'chunk' => $validated['chunk_index'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Chunk upload failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function status(Request $request, string $id)
    {
        try {
            $session = $this->sessionService->findOrFail($id);
            if (!$this->sessionService->ownedByUser($session, $request->user()->id)) {
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
            if (!$this->sessionService->ownedByUser($session, $request->user()->id)) {
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
            if (!$this->sessionService->ownedByUser($session, $request->user()->id)) {
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
            Log::channel('upload')->error('upload:finish_error', [
                'upload_id' => $validated['upload_id'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Upload completion failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, string $id)
    {
        try {
            $session = $this->sessionService->findOrFail($id);
            if (!$this->sessionService->ownedByUser($session, $request->user()->id)) {
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
}
