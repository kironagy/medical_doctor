<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Domains\Patients\Models\Patient;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Mobile\Resources\MobilePatientFileResource;
use App\Domains\ActivityLogs\Services\ActivityLogger;
use App\Helpers\NativePhp;
use App\Services\NetworkStatusService;
use App\Services\Mobile\ApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly ApiService $api,
        private readonly PatientFileRepositoryInterface $fileRepo,
        private readonly PatientRepositoryInterface $patientRepo
    ) {}

    public function index(Request $request, string $uuid)
    {
        if (NativePhp::isRunning() && NetworkStatusService::isOnline()) {
            try {
                $params = array_filter([
                    'category' => $request->get('category'),
                    'type' => $request->get('type'),
                    'per_page' => $request->integer('per_page', 50),
                ]);
                $response = $this->api->get("/patients/{$uuid}/files", $params);
                $files = $response['data'] ?? [];
                $this->cacheFilesLocally($uuid, $files);
                return response()->json($response);
            } catch (\Throwable $e) {
                Log::warning('[FileController] API index failed, falling back to local: ' . $e->getMessage());
            }
        }

        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('view', $patient);

        $query = $patient->files()->latest();
        if ($category = $request->get('category')) {
            $query->where('category', $category);
        }
        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }
        $files = $query->get();
        return response()->json([
            'data' => $files->map(fn($f) => new MobilePatientFileResource($f))->values(),
        ]);
    }

    public function show(string $fileUuid)
    {
        if (NativePhp::isRunning() && NetworkStatusService::isOnline()) {
            try {
                $response = $this->api->get("/files/{$fileUuid}");
                return response()->json($response);
            } catch (\Throwable $e) {
                Log::warning('[FileController] API show failed, falling back to local: ' . $e->getMessage());
            }
        }

        $file = PatientFile::where('uuid', $fileUuid)->firstOrFail();
        Gate::authorize('view', $file->patient);

        return response()->json(new MobilePatientFileResource($file));
    }

    public function store(Request $request, string $uuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('update', $patient);

        $validated = $request->validate([
            'file' => 'required|file|max:512000',
            'title' => 'sometimes|string|max:255',
            'desc' => 'sometimes|string|max:1000',
            'category' => 'sometimes|string|max:100',
            'date' => 'sometimes|date',
        ]);

        $uploadedFile = $request->file('file');
        $fileUuid = (string) \Illuminate\Support\Str::uuid();
        $extension = $uploadedFile->getClientOriginalExtension();
        $mimeType = $uploadedFile->getMimeType();
        $size = $uploadedFile->getSize();

        $originalName = $uploadedFile->getClientOriginalName();
        $originalName = preg_replace('/[^\w\.\-\(\) ]/', '_', $originalName);
        $originalName = ltrim($originalName, '.');

        $type = match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            str_starts_with($mimeType, 'application/pdf') => 'pdf',
            default => 'document',
        };

        $path = $uploadedFile->storeAs(
            "patients/{$uuid}",
            "{$fileUuid}.{$extension}",
            'local'
        );

        // Use repository for DB creation (handles API-first + local fallback)
        $fileData = $this->fileRepo->upload($uuid, [], [
            'uuid' => $fileUuid,
            'patient_id' => $patient->id,
            'uploaded_by_id' => $request->user()->id,
            'title' => $validated['title'] ?? $originalName,
            'desc' => $validated['desc'] ?? null,
            'type' => $type,
            'category' => $validated['category'] ?? null,
            'date' => $validated['date'] ?? now(),
            'file_name' => $originalName,
            'file_path' => $path,
            'mime_type' => $mimeType,
            'size' => $size,
            'upload_status' => 'ready',
        ]);

        $this->logger->log('file_uploaded', 'PatientFile', $fileData['uuid'] ?? '', [
            'patient_uuid' => $uuid,
            'file_name' => $fileData['file_name'] ?? $originalName,
        ]);

        $file = new PatientFile();
        $file->forceFill(\Illuminate\Support\Arr::except($fileData, ['id']));
        $file->exists = true;

        return response()->json(new MobilePatientFileResource($file), 201);
    }

    public function stream(Request $request, string $fileUuid)
    {
        $file = PatientFile::where('uuid', $fileUuid)->firstOrFail();
        Gate::authorize('view', $file->patient);

        $path = $file->file_path;
        if (!Storage::disk('local')->exists($path)) {
            abort(404, 'File not found on disk.');
        }

        $absolutePath = Storage::disk('local')->path($path);
        $mime = mime_content_type($absolutePath) ?: 'application/octet-stream';
        $size = filesize($absolutePath);

        return new StreamedResponse(function () use ($absolutePath) {
            $fp = fopen($absolutePath, 'rb');
            if ($fp) {
                $buf = 1024 * 1024;
                while (!feof($fp)) {
                    echo fread($fp, $buf);
                    fflush($fp);
                }
                fclose($fp);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Length' => $size,
            'Content-Disposition' => 'inline; filename="' . $file->file_name . '"',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-transform, max-age=3600',
        ]);
    }

    public function thumbnail(Request $request, string $fileUuid)
    {
        $file = PatientFile::where('uuid', $fileUuid)->firstOrFail();
        Gate::authorize('view', $file->patient);

        $path = $file->thumbnail_path;
        if ($path && Storage::disk('local')->exists($path)) {
            return response()->file(Storage::disk('local')->path($path), [
                'Content-Type'  => 'image/jpeg',
                'Cache-Control' => 'public, max-age=86400, immutable',
            ]);
        }

        if (str_starts_with($file->mime_type ?? '', 'image/')) {
            return $this->stream($request, $fileUuid);
        }

        return response()->noContent();
    }

    public function destroy(Request $request, string $fileUuid)
    {
        if (NativePhp::isRunning() && NetworkStatusService::isOnline()) {
            try {
                $this->api->delete("/files/{$fileUuid}");
                return response()->json(['message' => 'File deleted successfully']);
            } catch (\Throwable $e) {
                Log::warning('[FileController] API delete failed, falling back to local: ' . $e->getMessage());
            }
        }

        // Get file data for authorization before deletion
        $fileData = $this->fileRepo->find($fileUuid);
        if (!$fileData) abort(404);

        $file = new PatientFile();
        $file->forceFill(\Illuminate\Support\Arr::except($fileData, ['id']));
        $file->exists = true;
        Gate::authorize('update', $file->patient);

        // Disk cleanup (business logic stays in controller)
        $pathsToDelete = array_filter([
            $fileData['file_path'] ?? null,
            $fileData['thumbnail_path'] ?? null,
            $fileData['hls_path'] ?? null,
        ]);

        $disk = Storage::disk('local');
        $errors = [];

        foreach ($pathsToDelete as $path) {
            if (empty($path)) continue;
            try {
                $deleted = $disk->delete($path);
                if ($deleted) continue;
                try {
                    if ($disk->isDirectory($path)) {
                        $disk->deleteDirectory($path);
                    }
                } catch (\Throwable $e2) {
                    $msg2 = strtolower($e2->getMessage());
                    if (!str_contains($msg2, 'file not found') && !str_contains($msg2, 'no such file') && !str_contains($msg2, 'does not exist')) {
                        $errors[] = "Failed to delete directory '{$path}': " . $e2->getMessage();
                    }
                }
            } catch (\Throwable $e) {
                $msg = strtolower($e->getMessage());
                if (str_contains($msg, 'file not found') || str_contains($msg, 'no such file') || str_contains($msg, 'does not exist')) continue;
                $errors[] = "Failed to delete '{$path}': " . $e->getMessage();
                Log::error('File deletion error', ['uuid' => $fileUuid, 'path' => $path, 'exception' => $e]);
            }
        }

        if (!empty($errors)) {
            return response()->json(['message' => 'Failed to delete some files', 'errors' => $errors], 500);
        }

        // Database delete via repository
        try {
            $this->fileRepo->delete($fileUuid);
        } catch (\Throwable $e) {
            Log::error('Failed to soft delete PatientFile', ['uuid' => $fileUuid, 'exception' => $e]);
            return response()->json(['message' => 'Failed to delete file record', 'errors' => [(string) $e->getMessage()]], 500);
        }

        $this->logger->log('file_deleted', 'PatientFile', $fileUuid, [
            'file_name' => $fileData['file_name'] ?? 'Unknown',
        ]);

        return response()->json(['message' => 'File deleted successfully']);
    }

    public function update(Request $request, string $fileUuid)
    {
        if (NativePhp::isRunning() && NetworkStatusService::isOnline()) {
            try {
                $validated = $request->validate([
                    'title' => 'sometimes|required|string|max:255',
                    'desc' => 'sometimes|string|nullable',
                    'category' => 'sometimes|string|nullable',
                ]);

                $response = $this->api->put("/files/{$fileUuid}", $validated);
                return response()->json($response);
            } catch (\Throwable $e) {
                Log::warning('[FileController] API update failed, falling back to local: ' . $e->getMessage());
            }
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'desc' => 'sometimes|string|nullable',
            'category' => 'sometimes|string|nullable',
        ]);

        if (empty($validated)) {
            return response()->json(['message' => 'At least one field must be provided.'], 422);
        }

        // Use repo which handles API-first + local fallback
        $fileData = $this->fileRepo->update($fileUuid, $validated);

        $file = new PatientFile();
        $file->forceFill(\Illuminate\Support\Arr::except($fileData, ['id']));
        $file->exists = true;

        return response()->json(new MobilePatientFileResource($file));
    }

    private function cacheFilesLocally(string $patientUuid, array $files): void
    {
        $patient = Patient::where('uuid', $patientUuid)->first();
        if (!$patient) return;

        foreach ($files as $item) {
            if (!isset($item['uuid'])) continue;

            $cleanData = \Illuminate\Support\Arr::except($item, ['id', 'patient', 'creator', 'uploader']);
            $cleanData['patient_id'] = $patient->id;

            try {
                PatientFile::withoutGlobalScopes()->updateOrCreate(
                    ['uuid' => $item['uuid']],
                    $cleanData
                );
            } catch (\Throwable $e) {
                Log::warning('[FileController] Failed to cache file locally: ' . $e->getMessage());
            }
        }
    }
}
