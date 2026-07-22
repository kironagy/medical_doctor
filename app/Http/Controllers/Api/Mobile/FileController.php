<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Domains\Patients\Models\Patient;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Mobile\Resources\MobilePatientFileResource;
use App\Domains\ActivityLogs\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger,
    ) {}

    public function index(Request $request, string $uuid)
    {
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

        $file = $patient->files()->create([
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

        $this->logger->log('file_uploaded', 'PatientFile', $file->uuid, [
            'patient_uuid' => $uuid,
            'file_name' => $file->file_name,
        ]);

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
        $file = PatientFile::where('uuid', $fileUuid)->firstOrFail();
        Gate::authorize('update', $file->patient);

        // Disk cleanup
        $pathsToDelete = array_filter([
            $file->file_path,
            $file->thumbnail_path,
            $file->hls_path,
        ]);

        $disk = Storage::disk('local');
        foreach ($pathsToDelete as $path) {
            if (empty($path)) continue;
            try {
                $disk->delete($path);
            } catch (\Throwable $e) {
                Log::warning('File deletion disk error', ['uuid' => $fileUuid, 'path' => $path, 'error' => $e->getMessage()]);
            }
        }

        $file->delete();

        $this->logger->log('file_deleted', 'PatientFile', $fileUuid, [
            'file_name' => $file->file_name,
        ]);

        return response()->json(['message' => 'File deleted successfully']);
    }

    public function update(Request $request, string $fileUuid)
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'desc' => 'sometimes|string|nullable',
            'category' => 'sometimes|string|nullable',
        ]);

        if (empty($validated)) {
            return response()->json(['message' => 'At least one field must be provided.'], 422);
        }

        $file = PatientFile::where('uuid', $fileUuid)->firstOrFail();
        $file->update($validated);

        return response()->json(new MobilePatientFileResource($file->fresh()));
    }
}
