<?php

namespace App\Repositories;

use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Repositories\Eloquent\EloquentPatientRepository;
use App\Domains\Sync\Services\SyncQueueService;
use App\Services\Mobile\RemoteApiService;
use App\Domains\Media\Models\PatientFile;
use App\Exceptions\OfflineWriteNotAllowedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * PatientRepository — Online-Direct Persistence Repository
 * ───────────────────────────────────────────────────────────────────────────
 *
 * Production (MySQL) is the sole source of truth. Every write here is a
 * normal single-database write. The embedded on-device instance (SQLite)
 * only ever reaches these methods when the device is genuinely offline (see
 * RequestRouter.kt — online mutations are routed directly to production and
 * never touch this instance at all), in which case the write is rejected
 * rather than staged as a local pending_* row — there is no local-first
 * write path anymore. $queueService and deleteRemoteDirectly() are now
 * unreachable from this class; left in place pending the Phase 5 removal of
 * the old sync system rather than touched here.
 * ───────────────────────────────────────────────────────────────────────────
 */
class PatientRepository implements PatientRepositoryInterface
{
    private EloquentPatientRepository $local;
    private SyncQueueService $queueService;

    public function __construct(
        ?EloquentPatientRepository $local = null,
        ?SyncQueueService $queueService = null,
    ) {
        $this->local = $local ?? app(EloquentPatientRepository::class);
        $this->queueService = $queueService ?? app(SyncQueueService::class);
    }

    public function all(): array
    {
        $data = $this->local->all();
        return array_values(array_filter($data, fn($p) => ($p['sync_status'] ?? 'synced') !== 'pending_delete'));
    }

    public function paginated(int $perPage = 10, int $page = 1, ?string $status = null): array
    {
        return $this->local->paginated($perPage, $page, $status);
    }

    public function find(string $uuid): ?array
    {
        $data = $this->local->find($uuid);
        if ($data && ($data['sync_status'] ?? 'synced') === 'pending_delete') {
            return null;
        }
        return $data;
    }

    public function findByUuid(string $uuid): array
    {
        $result = $this->find($uuid);
        if (!$result) throw new \RuntimeException('Patient not found.');
        return $result;
    }

    /**
     * True only for the embedded NativePHP mobile build (local SQLite cache).
     * The website runs on MySQL and IS the source of truth — its writes must
     * never be staged as pending_create/pending_update/pending_delete or
     * pushed into sync_queue; that mechanism exists solely for offline
     * mobile devices reconciling back up to this same server.
     */
    private function isOfflineDevice(): bool
    {
        return config('database.default') === 'sqlite';
    }

    /**
     * The patient list the user deletes from is fetched live from the
     * remote server (paginated() only ever reads the local cache, but the
     * workspace list itself hits production directly), so a patient can be
     * visible and deletable in the UI without ever having been pulled down
     * into this device's local SQLite. When that happens, delete()/
     * forceDelete() find no local row to mark pending_delete and used to
     * silently return — the delete never reached the server at all. Call
     * the remote API directly in that case so it always actually deletes.
     */
    private function deleteRemoteDirectly(string $uuid): void
    {
        try {
            app(RemoteApiService::class)->delete("/mobile/patients/{$uuid}");
        } catch (Throwable $e) {
            if (!str_contains($e->getMessage(), '404')) {
                Log::warning('[PatientRepository] Remote delete for uncached patient failed: ' . $e->getMessage(), [
                    'uuid' => $uuid,
                ]);
            }
        }
    }

    /**
     * Wipe every physical file (image, video, thumbnail, HLS segment) that
     * belongs to this patient's uploads from local disk storage. Force-deleting
     * a patient previously only removed the `patient_files` DB rows via the
     * table's FK cascadeOnDelete — the actual bytes on `storage/app` were
     * never touched and piled up as orphaned files. withTrashed() covers
     * files a user already soft-deleted individually before the patient
     * itself was force-deleted.
     */
    private function deletePatientFiles(int $patientId): void
    {
        $files = PatientFile::withTrashed()->where('patient_id', $patientId)->get();
        if ($files->isEmpty()) {
            return;
        }

        $disk = Storage::disk('local');
        foreach ($files as $file) {
            foreach (array_filter([$file->file_path, $file->thumbnail_path, $file->hls_path]) as $path) {
                try {
                    if ($disk->isDirectory($path)) {
                        $disk->deleteDirectory($path);
                    } else {
                        $disk->delete($path);
                    }
                } catch (Throwable $e) {
                    Log::warning('[PatientRepository] Failed to delete patient file from storage', [
                        'patient_id' => $patientId,
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    public function create(array $data): array
    {
        if ($this->isOfflineDevice()) {
            throw new OfflineWriteNotAllowedException();
        }

        $data['sync_status'] = 'synced';
        return $this->local->create($data);
    }

    public function update(string $uuid, array $data): array
    {
        if ($this->isOfflineDevice()) {
            throw new OfflineWriteNotAllowedException();
        }

        $data['sync_status'] = 'synced';
        return $this->local->update($uuid, $data);
    }

    public function delete(string $uuid): void
    {
        if ($this->isOfflineDevice()) {
            throw new OfflineWriteNotAllowedException();
        }

        \App\Domains\Patients\Models\Patient::where('uuid', $uuid)->delete();
    }

    public function search(string $term): array
    {
        return $this->local->search($term);
    }

    public function shared(int $userId): array
    {
        return $this->local->shared($userId);
    }

    public function stats(): array
    {
        return $this->local->stats();
    }

    public function recent(int $limit): array
    {
        return $this->local->recent($limit);
    }

    public function withTrashed(): array
    {
        return $this->local->withTrashed();
    }

    public function restore(string $uuid): void
    {
        if ($this->isOfflineDevice()) {
            throw new OfflineWriteNotAllowedException();
        }

        $this->local->restore($uuid);
        $this->local->update($uuid, ['sync_status' => 'synced']);
    }

    public function forceDelete(string $uuid): void
    {
        if ($this->isOfflineDevice()) {
            throw new OfflineWriteNotAllowedException();
        }

        $patient = \App\Domains\Patients\Models\Patient::withoutGlobalScope(
            \App\Domains\Auth\Scopes\DoctorIsolationScope::class
        )->withTrashed()->where('uuid', $uuid)->first();

        if ($patient) {
            $this->deletePatientFiles($patient->id);
            $patient->forceDelete();
        }
    }
}
