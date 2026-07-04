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

class ChunkUploadController extends Controller
{
    public function __construct(
        private readonly UploadSessionService $sessionService,
        private readonly ChunkUploadService $chunkService,
        private readonly ChunkMergeService $mergeService,
        private readonly UploadCleanupService $cleanupService,
        private readonly UploadValidationService $validationService,
    ) {}

    public function init(Request $request)
    {
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
            'chunk_size' => $request->input('chunk_size', 5 * 1024 * 1024),
            'metadata' => $request->input('metadata'),
        ]);

        $session = $this->sessionService->create($data, $request->user()->id);

        return response()->json([
            'upload_id' => $session->uuid,
            'chunk_size' => $session->chunk_size,
            'total_chunks' => $session->total_chunks,
            'total_size' => $session->total_size,
            'expires_at' => $session->expires_at->toIso8601String(),
        ]);
    }

    public function chunk(Request $request)
    {
        $request->validate([
            'upload_id' => 'required|string|size:36',
            'chunk_index' => 'required|integer|min:0',
            'chunk' => 'required|file|max:51200',
        ]);

        $session = $this->sessionService->findOrFail($request->upload_id);
        if (!$this->sessionService->ownedByUser($session, $request->user()->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $result = $this->chunkService->storeChunk(
            $session,
            $request->file('chunk'),
            (int) $request->chunk_index
        );

        return response()->json($result);
    }

    public function complete(Request $request)
    {
        $request->validate([
            'upload_id' => 'required|string|size:36',
        ]);

        $session = $this->sessionService->findOrFail($request->upload_id);
        if (!$this->sessionService->ownedByUser($session, $request->user()->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $patientFile = $this->mergeService->merge($session);

        return response()->json([
            'uuid' => $patientFile->uuid,
            'upload_status' => $patientFile->upload_status,
            'url' => $patientFile->url,
            'thumbnail_url' => $patientFile->thumbnail_url,
            'type' => $patientFile->type,
        ]);
    }

    public function cancel(Request $request, string $uuid)
    {
        $session = $this->sessionService->findOrFail($uuid);
        if (!$this->sessionService->ownedByUser($session, $request->user()->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $this->chunkService->cancel($session);

        return response()->json(['message' => 'Upload cancelled']);
    }

    public function status(Request $request, string $uuid)
    {
        $session = $this->sessionService->findOrFail($uuid);
        if (!$this->sessionService->ownedByUser($session, $request->user()->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($this->chunkService->getStatus($session));
    }
}
