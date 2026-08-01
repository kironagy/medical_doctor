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
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Repositories\Api\ApiPatientRepository;

class FileController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger
    ) {}

    public function index(Request $request, string $uuid)
    {
        $patient = $this->resolvePatient($uuid);
        if ($request->user()) {
            Gate::authorize('view', $patient);
        }

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
        if (request()->user()) {
            Gate::authorize('view', $file->patient);
        }

        return response()->json(new FileResource($file));
    }

    public function store(Request $request, string $uuid)
    {
        $patient = $this->resolvePatient($uuid);
        if ($request->user()) {
            Gate::authorize('update', $patient);
        }

        $validated = $request->validate([
            'file' => 'required|file|max:512000',
            'title' => 'sometimes|string|max:255',
            'desc' => 'sometimes|string|nullable|max:1000',
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

        // ── Issue 2 FIX: Build create payload without sync_status on MySQL ──────
        // The sync_status column has a DB default of 'synced'. On MySQL (production),
        // we must NOT pass sync_status at all — passing explicit null would override
        // the DB default and store NULL, breaking sync_status-based queries on the
        // production server.
        // On SQLite (embedded app), set 'pending_sync' so SyncEngine finds this file.
        $createPayload = [
            'uuid'           => $fileUuid,
            'patient_id'     => $patient->id,
            'uploaded_by_id' => $request->user()?->id ?? $patient->primary_doctor_id ?? 1,
            'title'          => $validated['title'] ?? $uploadedFile->getClientOriginalName(),
            'desc'           => $validated['desc'] ?? null,
            'type'           => $type,
            'category'       => $validated['category'] ?? null,
            'date'           => $validated['date'] ?? now(),
            'file_name'      => $uploadedFile->getClientOriginalName(),
            'file_path'      => $path,
            'mime_type'      => $mimeType,
            'size'           => $size,
            'upload_status'  => 'ready',
        ];
        if (config('database.default') === 'sqlite') {
            $createPayload['sync_status'] = 'pending_sync';
        }
        $file = PatientFile::create($createPayload);

        $this->logger->log('file_uploaded', 'PatientFile', $file->uuid, [
            'patient_uuid' => $uuid,
            'file_name' => $file->file_name,
        ]);

        return response()->json(new FileResource($file), 201);
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
        // ═══ SYNC-007 FIX: Bypass DoctorIsolationScope ═══════════════════
        // On SQLite with no authenticated user, DoctorIsolationScope filters
        // by primary_doctor_id which is null — causing 404 before our logic runs.
        // Auth check comes AFTER the query so the file can be found first.
        $file = PatientFile::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )
            ->where('uuid', $fileUuid)
            ->firstOrFail();

        // SQLite guard: Skip Gate when no authenticated user
        if ($request->user()) {
            Gate::authorize('update', $file->patient);
        }

        // Collect paths to delete
        $pathsToDelete = array_filter([
            $file->file_path,
            $file->thumbnail_path,
            $file->hls_path,
        ]);

        $disk = Storage::disk('local');
        $errors = [];

        foreach ($pathsToDelete as $path) {
            if (empty($path)) continue;
            try {
                // First try delete as file (works for files and may also delete empty dirs depending on driver)
                $deleted = $disk->delete($path);
                if ($deleted) {
                    continue;
                }
                // If not deleted, check if it's a directory and attempt recursive delete
                try {
                    if ($disk->isDirectory($path)) {
                        $disk->deleteDirectory($path);
                    }
                } catch (\Throwable $e2) {
                    // If isDirectory fails because file doesn't exist, ignore
                    $msg2 = strtolower($e2->getMessage());
                    if (!str_contains($msg2, 'file not found') && !str_contains($msg2, 'no such file') && !str_contains($msg2, 'does not exist')) {
                        $errors[] = "Failed to delete directory '{$path}': " . $e2->getMessage();
                    }
                }
            } catch (\Throwable $e) {
                // Main delete threw; check if it's a "not found" error
                $msg = strtolower($e->getMessage());
                if (str_contains($msg, 'file not found') || str_contains($msg, 'no such file') || str_contains($msg, 'does not exist')) {
                    continue;
                }
                $errors[] = "Failed to delete '{$path}': " . $e->getMessage();
                Log::error('File deletion error', [
                    'uuid' => $fileUuid,
                    'path' => $path,
                    'exception' => $e,
                ]);
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'message' => 'Failed to delete some files',
                'errors' => $errors,
            ], 500);
        }

        // ═══ SYNC-008 FIX: Mark file as pending_delete on SQLite ═══════════
        // On the embedded Laravel (SQLite), we must NOT force-delete the file
        // immediately. Instead, we mark it as pending_delete so the sync engine
        // can delete it from the production server first.
        //
        // On MySQL (production), we use forceDelete as before.
        if (config('database.default') === 'sqlite') {
            $file->update([
                'sync_status' => 'pending_delete',
                'client_updated_at' => now(),
            ]);
        } else {
            try {
                $file->forceDelete();
            } catch (\Throwable $e) {
                Log::error('Failed to force delete PatientFile', ['uuid' => $fileUuid, 'exception' => $e]);
                return response()->json([
                    'message' => 'Failed to delete file record',
                    'errors' => [(string) $e->getMessage()],
                ], 500);
            }
        }

        $this->logger->log('file_deleted', 'PatientFile', $file->uuid, [
            'file_name' => $file->file_name,
        ]);

        return response()->json(['message' => 'File deleted successfully']);
    }

    public function update(Request $request, string $fileUuid)
    {
        // ═══ SYNC-007 FIX: Bypass DoctorIsolationScope ═══════════════════
        $file = PatientFile::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )
            ->where('uuid', $fileUuid)
            ->firstOrFail();

        // SQLite guard: Skip Gate when no authenticated user
        if ($request->user()) {
            Gate::authorize('update', $file->patient);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'desc' => 'sometimes|string|nullable',
            'category' => 'sometimes|string|nullable',
        ]);

        if (empty($validated)) {
            return response()->json(['message' => 'At least one field must be provided.'], 422);
        }

        // ═══ SYNC-008 FIX: Mark file as pending_update on SQLite ═══════════
        // On the embedded Laravel (SQLite), metadata updates must be marked
        // as pending_update so the sync engine uploads them to production.
        if (config('database.default') === 'sqlite') {
            $file->update(array_merge($validated, [
                'sync_status' => 'pending_update',
                'client_updated_at' => now(),
            ]));
        } else {
            $file->update($validated);
        }

        return response()->json(new FileResource($file->fresh()));
    }

    private function resolvePatient(string $uuid): Patient
    {
        $patient = Patient::where('uuid', $uuid)->first();
        if ($patient) {
            return $patient;
        }

        try {
            $apiPatient = app(ApiPatientRepository::class)->find($uuid);
            if ($apiPatient) {
                $cleanData = \Illuminate\Support\Arr::except($apiPatient, [
                    'id', 'primary_doctor', 'visits', 'shares', 'files', 'notes',
                ]);
                $cleanData['sync_status'] = 'synced';

                Patient::unguard();
                $patient = Patient::updateOrCreate(['uuid' => $uuid], $cleanData);
                Patient::reguard();

                if ($patient) {
                    return $patient;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('resolvePatient API fallback failed', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);
        }

        $stubData = [
            'uuid' => $uuid,
            'sync_status' => 'pending_sync',
            'name' => 'Patient (' . $uuid . ')',
        ];
        $userId = auth()->id();
        if (!$userId && config('database.default') === 'sqlite') {
            $user = \App\Domains\Users\Models\User::first();
            if (!$user) {
                \App\Domains\Users\Models\User::unguard();
                $user = \App\Domains\Users\Models\User::firstOrCreate(
                    ['id' => 1],
                    [
                        'name' => 'Default Doctor',
                        'email' => 'doctor@local.test',
                        'password' => bcrypt('password'),
                    ]
                );
                \App\Domains\Users\Models\User::reguard();
            }
            $userId = $user->id;
        }

        if ($userId) {
            $stubData['primary_doctor_id'] = $userId;
            $stubData['created_by_id'] = $userId;
        }

        $patient = Patient::updateOrCreate(
            ['uuid' => $uuid],
            $stubData
        );

        return $patient;
    }
}
