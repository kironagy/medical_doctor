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
        // ⚠️ Must include primary_doctor_id: PatientPolicy::view() checks
        // $patient->primary_doctor_id === $user->id, and a column left out of
        // a scoped with() comes back null (not lazy-loaded). With only
        // id/uuid selected, primary_doctor_id was always null, so the check
        // was always false and every doctor — including a patient's own
        // primary doctor — got a 403 here. This is what made a file uploaded
        // on the website unreachable from the device: the on-device hydration
        // path (FileCacheRepository::hydrateFileFromRemote()) calls this
        // exact endpoint to fetch the file's metadata before it can cache the
        // bytes, and it always failed before ever reaching the Storage lookup.
        $file = PatientFile::withoutGlobalScope(
            \App\Domains\Auth\Scopes\DoctorIsolationScope::class
        )->with('patient:id,uuid,primary_doctor_id')->where(function ($q) use ($fileUuid) {
            $q->where('uuid', $fileUuid)->orWhere('remote_uuid', $fileUuid);
        })->firstOrFail();
        if (config('database.default') !== 'sqlite' && request()->user()) {
            Gate::authorize('view', $file->patient);
        }

        return response()->json(new FileResource($file));
    }

    /**
     * List locally-pending offline uploads (files saved to SQLite but not yet
     * synced to the production server).
     *
     * Used by the frontend (CategoryBlock.loadCategoryData, useWorkspace
     * selectPatient rehydration) to merge pending uploads into the UI even
     * before the sync engine has pushed them to production.
     */
    public function pendingIndex(Request $request)
    {
        $patientUuid = $request->input('patient_uuid');
        $repo = app(\App\Contracts\Repositories\OfflineFileRepositoryInterface::class);

        $files = $patientUuid
            ? $repo->findByPatientUuid($patientUuid)
            : $repo->findPending();

        return response()->json(['data' => $files]);
    }

    public function store(Request $request, ?string $uuid = null)
    {
        $patientUuid = $uuid ?: $request->input('patient_uuid');
        $uuid = $patientUuid; // Fix route vs request input mapping
        if (!$patientUuid) {
            return response()->json(['message' => 'patient_uuid is required'], 422);
        }
        $patient = $this->resolvePatient($patientUuid);
        if ($request->user()) {
            Gate::authorize('update', $patient);
        }

        $validated = $request->validate([
            'file'     => 'required|file|max:512000',
            'title'    => 'nullable|string|max:255',
            'desc'     => 'nullable|string|max:1000',
            'category' => 'nullable|string|max:100',
            'date'     => 'nullable|date',
        ]);

        // TEMPORARY (category investigation): record what the client actually
        // sent so we can tell a dropped category apart from one the sync
        // later overwrote. Remove once the category flow is confirmed.
        \Illuminate\Support\Facades\Log::info('[UploadCategory] store', [
            'patient_uuid'      => $patientUuid,
            'category_received' => $request->input('category'),
            'category_valid'    => $validated['category'] ?? null,
            'all_keys'          => array_keys($request->all()),
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

        if ($type === 'document') {
            $ext = strtolower($extension);
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'tiff', 'tif'])) {
                $type = 'image';
                $mimeType = match($ext) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                    'heic' => 'image/heic',
                    default => 'image/' . $ext,
                };
            } elseif (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', '3gp', 'wmv', 'flv'])) {
                $type = 'video';
                $mimeType = match($ext) {
                    'mp4' => 'video/mp4',
                    'mov' => 'video/quicktime',
                    'avi' => 'video/x-msvideo',
                    'mkv' => 'video/x-matroska',
                    'webm' => 'video/webm',
                    default => 'video/' . $ext,
                };
            }
        }

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
        //
        // ── FK FIX: Use resolveCurrentUserId() instead of hardcoded ?? 1 ────────
        // The original code used `$patient->primary_doctor_id ?? 1`, which fails
        // when the local user's ID is not 1 (e.g. the local SQLite seeded doctor
        // gets ID 2 on a device where ID 1 was taken by a different record).
        // resolveCurrentUserId() always returns a valid, existing user ID.
        $uploadedById = $request->user()?->id ?? $this->resolveCurrentUserId();

        $createPayload = [
            'uuid'           => $fileUuid,
            'patient_id'     => $patient->id,
            'uploaded_by_id' => $uploadedById,
            'title'          => $validated['title'] ?? $uploadedFile->getClientOriginalName(),
            'desc'           => $validated['desc'] ?? null,
            'type'           => $type,
            'category'       => !empty($validated['category']) ? $validated['category'] : 'notes',
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

    /**
     * Resolve the current user ID for FK assignment.
     *
     * Priority:
     *   1. Authenticated user (session/Sanctum)
     *   2. First user in local SQLite (offline single-user device)
     *   3. Seed a default doctor (clean install with empty DB)
     *
     * This replaces the old `$patient->primary_doctor_id ?? 1` hardcoded fallback,
     * which fails when the local user ID ≠ 1.
     */
    private function resolveCurrentUserId(): int
    {
        $user = auth()->user();
        if ($user) {
            return $user->id;
        }

        $localUser = \App\Domains\Users\Models\User::first();
        if ($localUser) {
            return $localUser->id;
        }

        // Absolute last resort: seed default doctor on clean install
        \App\Domains\Users\Models\User::unguard();
        $defaultUser = \App\Domains\Users\Models\User::firstOrCreate(
            ['email' => 'doctor@local.test'],
            [
                'name'     => 'Default Doctor',
                'password' => bcrypt('password'),
                'role'     => 'doctor',
                'uuid'     => (string) \Illuminate\Support\Str::uuid(),
                'status'   => 'active',
            ]
        );
        \App\Domains\Users\Models\User::reguard();

        Log::info('[FileController] Seeded default doctor for clean install', [
            'doctor_id' => $defaultUser->id,
        ]);

        return $defaultUser->id;
    }

    public function stream(Request $request, string $fileUuid)
    {
        $file = PatientFile::withoutGlobalScope(
            \App\Domains\Auth\Scopes\DoctorIsolationScope::class
        )->where(function ($q) use ($fileUuid) {
            $q->where('uuid', $fileUuid)->orWhere('remote_uuid', $fileUuid);
        })->firstOrFail();

        if (config('database.default') !== 'sqlite' && $request->user()) {
            Gate::authorize('view', $file->patient);
        }

        $path = $file->file_path;
        if (!Storage::disk('local')->exists($path)) {
            abort(404, 'File not found on disk.');
        }

        $absolutePath = Storage::disk('local')->path($path);
        $mime = $file->mime_type ?: (mime_content_type($absolutePath) ?: 'application/octet-stream');
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
        $file = PatientFile::withoutGlobalScope(
            \App\Domains\Auth\Scopes\DoctorIsolationScope::class
        )->where(function ($q) use ($fileUuid) {
            $q->where('uuid', $fileUuid)->orWhere('remote_uuid', $fileUuid);
        })->firstOrFail();

        if (config('database.default') !== 'sqlite' && $request->user()) {
            Gate::authorize('view', $file->patient);
        }

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

        // ── NOT NULL FIX (500 error on fresh install) ─────────────────────
        // On a clean install the local users table can be EMPTY. Using only
        // auth()->id() + User::first() left primary_doctor_id null, causing
        // SQLite 'NOT NULL constraint failed: patients.primary_doctor_id'
        // → HTTP 500 on the very first upload/note/visit for a new patient.
        // resolveCurrentUserId() guarantees a valid user ID (and seeds a
        // default doctor as an absolute last resort), so the stub patient
        // always satisfies the NOT NULL constraint.
        $userId = $this->resolveCurrentUserId();

        $stubData = [
            'uuid'              => $uuid,
            'sync_status'       => 'pending_create',
            'name'              => 'Patient (' . substr($uuid, 0, 8) . ')',
            'primary_doctor_id' => $userId,
            'created_by_id'     => $userId,
        ];

        return Patient::create($stubData);
    }
}
