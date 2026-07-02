<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Media\Models\UploadSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChunkSyncController extends Controller
{
    public function init(Request $request): JsonResponse
    {
        $request->validate([
            'patient_uuid' => 'required|string',
            'file_name' => 'required|string|max:255',
            'mime_type' => 'required|string|max:255',
            'total_size' => 'required|integer|min:1',
            'total_chunks' => 'required|integer|min:1',
            'checksum' => 'nullable|string',
        ]);

        $patient = \App\Domains\Patients\Models\Patient::where('uuid', $request->patient_uuid)->firstOrFail();
        $this->authorize('view', $patient);

        $session = UploadSession::create([
            'uuid' => (string) Str::uuid(),
            'patient_id' => $patient->id,
            'user_id' => $request->user()->id,
            'original_name' => $request->file_name,
            'mime_type' => $request->mime_type,
            'extension' => pathinfo($request->file_name, PATHINFO_EXTENSION),
            'total_size' => $request->total_size,
            'total_chunks' => $request->total_chunks,
            'chunk_size' => 0,
            'status' => 'pending',
            'checksum_algorithm' => 'sha256',
            'final_checksum' => $request->checksum,
            'expires_at' => now()->addHours(24),
        ]);

        $chunkDir = $session->chunkDir();
        Storage::disk('local')->makeDirectory($chunkDir);

        return response()->json([
            'session_uuid' => $session->uuid,
            'total_chunks' => $session->total_chunks,
            'chunk_size' => $session->chunk_size,
        ], 201);
    }

    public function upload(Request $request, string $sessionUuid): JsonResponse
    {
        $session = UploadSession::where('uuid', $sessionUuid)->firstOrFail();

        if ($session->status === 'completed' || $session->status === 'cancelled') {
            return response()->json(['message' => 'Session is already ' . $session->status . '.'], 400);
        }

        $request->validate([
            'chunk_index' => 'required|integer|min:0',
            'chunk' => 'required|file|max:134217728',
        ]);

        $chunkIndex = $request->chunk_index;

        if ($chunkIndex >= $session->total_chunks) {
            return response()->json(['message' => 'Chunk index out of range.'], 422);
        }

        $session->update(['status' => 'uploading']);

        $chunkDir = $session->chunkDir();
        $chunkPath = "{$chunkDir}/{$chunkIndex}";

        $request->file('chunk')->storeAs(dirname($chunkPath), basename($chunkPath), 'local');

        $receivedChunks = count(Storage::disk('local')->files($chunkDir));
        $isComplete = $receivedChunks >= $session->total_chunks;

        if ($isComplete) {
            $session->update(['status' => 'uploaded']);
        }

        return response()->json([
            'chunk_index' => $chunkIndex,
            'received' => $receivedChunks,
            'total' => $session->total_chunks,
            'is_complete' => $isComplete,
        ]);
    }

    public function complete(Request $request, string $sessionUuid): JsonResponse
    {
        $session = UploadSession::where('uuid', $sessionUuid)->firstOrFail();

        if ($session->status === 'completed') {
            return response()->json(['message' => 'Session already completed.'], 400);
        }

        $patient = $session->patient;
        $user = $request->user();

        $fileUuid = (string) Str::uuid();
        $extension = $session->extension;
        $fileName = pathinfo($session->original_name, PATHINFO_FILENAME) . '_' . now()->format('Ymd_His') . '.' . $extension;
        $relativePath = 'patients/' . $patient->uuid . '/' . $fileUuid . '.' . $extension;
        $absoluteDir = Storage::disk('local')->path(dirname($relativePath));

        if (!is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0755, true);
        }

        $chunkDir = $session->chunkDir();
        $chunks = Storage::disk('local')->files($chunkDir);
        sort($chunks);

        $finalPath = Storage::disk('local')->path($relativePath);
        $out = fopen($finalPath, 'wb');

        foreach ($chunks as $chunk) {
            $chunkContent = Storage::disk('local')->get($chunk);
            fwrite($out, $chunkContent);
        }
        fclose($out);

        $fileSize = filesize($finalPath);
        $mimeType = mime_content_type($finalPath) ?: $session->mime_type;

        $patientFile = PatientFile::create([
            'uuid' => $fileUuid,
            'patient_id' => $patient->id,
            'uploaded_by_id' => $session->user_id,
            'file_name' => $session->original_name,
            'file_path' => $relativePath,
            'mime_type' => $mimeType,
            'size' => $fileSize,
            'type' => explode('/', $mimeType)[0] ?? 'unknown',
            'upload_status' => 'ready',
        ]);

        if (str_starts_with($mimeType, 'video/')) {
            \App\Domains\Media\Jobs\GenerateThumbnailJob::dispatch($patientFile);
        }

        $session->update(['status' => 'completed']);

        Storage::disk('local')->deleteDirectory($chunkDir);

        return response()->json([
            'file' => [
                'uuid' => $patientFile->uuid,
                'file_name' => $patientFile->file_name,
                'mime_type' => $patientFile->mime_type,
                'size' => $patientFile->size,
                'url' => $patientFile->url,
                'thumbnail_url' => $patientFile->thumbnail_url,
            ],
        ], 201);
    }

    public function status(Request $request, string $sessionUuid): JsonResponse
    {
        $session = UploadSession::where('uuid', $sessionUuid)->firstOrFail();

        $chunkDir = $session->chunkDir();
        $receivedChunks = Storage::disk('local')->files($chunkDir);

        return response()->json([
            'session_uuid' => $session->uuid,
            'status' => $session->status,
            'total_chunks' => $session->total_chunks,
            'received_chunks' => count($receivedChunks),
            'progress_percent' => $session->total_chunks > 0
                ? round((count($receivedChunks) / $session->total_chunks) * 100, 1)
                : 0,
        ]);
    }
}
