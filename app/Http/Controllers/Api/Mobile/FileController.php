<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Domains\Patients\Models\Patient;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Media\Resources\FileResource;
use App\Domains\ActivityLogs\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger
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

        $files = $query->paginate(min($request->integer('per_page', 50), 100));

        return FileResource::collection($files);
    }

    public function show(string $fileUuid)
    {
        $file = PatientFile::where('uuid', $fileUuid)->firstOrFail();
        Gate::authorize('view', $file->patient);

        return response()->json(new FileResource($file));
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

        $file = PatientFile::create([
            'uuid' => $fileUuid,
            'patient_id' => $patient->id,
            'uploaded_by_id' => $request->user()->id,
            'title' => $validated['title'] ?? $uploadedFile->getClientOriginalName(),
            'desc' => $validated['desc'] ?? null,
            'type' => $type,
            'category' => $validated['category'] ?? null,
            'date' => $validated['date'] ?? now(),
            'file_name' => $uploadedFile->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $mimeType,
            'size' => $size,
            'upload_status' => 'ready',
        ]);

        $this->logger->log('file_uploaded', 'PatientFile', $file->uuid, [
            'patient_uuid' => $uuid,
            'file_name' => $file->file_name,
        ]);

        return response()->json(new FileResource($file), 201);
    }

    public function destroy(Request $request, string $fileUuid)
    {
        $file = PatientFile::where('uuid', $fileUuid)->firstOrFail();
        Gate::authorize('delete', $file->patient);

        $file->delete();

        $this->logger->log('file_deleted', 'PatientFile', $file->uuid, [
            'file_name' => $file->file_name,
        ]);

        return response()->json(['message' => 'File deleted successfully']);
    }
}
