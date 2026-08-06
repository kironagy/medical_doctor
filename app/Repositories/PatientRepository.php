<?php

namespace App\Repositories;

use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Repositories\Eloquent\EloquentPatientRepository;
use App\Domains\Sync\Services\SyncQueueService;
use App\Services\Mobile\RemoteApiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * PatientRepository — Offline-First Pure Persistence Repository
 * ───────────────────────────────────────────────────────────────────────────
 *
 * In the Offline-First Architecture, SQLite is the sole source of truth
 * during mobile application usage. This repository performs all CRUD operations
 * directly against SQLite via Eloquent inside DB::transaction(), setting
 * sync_status flags and pushing queue records into sync_queue atomically.
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

    public function create(array $data): array
    {
        if (!$this->isOfflineDevice()) {
            $data['sync_status'] = 'synced';
            return $this->local->create($data);
        }

        return DB::transaction(function () use ($data) {
            $data['sync_status'] = 'pending_create';
            $data['client_updated_at'] = now();
            $patient = $this->local->create($data);

            $this->queueService->push('patient', $patient['uuid'], 'create', $patient);
            return $patient;
        });
    }

    public function update(string $uuid, array $data): array
    {
        if (!$this->isOfflineDevice()) {
            $data['sync_status'] = 'synced';
            return $this->local->update($uuid, $data);
        }

        return DB::transaction(function () use ($uuid, $data) {
            $data['sync_status'] = 'pending_update';
            $data['client_updated_at'] = now();
            $patient = $this->local->update($uuid, $data);

            $this->queueService->push('patient', $uuid, 'update', $patient);
            return $patient;
        });
    }

    public function delete(string $uuid): void
    {
        if (!$this->isOfflineDevice()) {
            \App\Domains\Patients\Models\Patient::where('uuid', $uuid)->delete();
            return;
        }

        DB::transaction(function () use ($uuid) {
            $hasRemoteUuid = \Illuminate\Support\Facades\Schema::hasColumn('patients', 'remote_uuid');
            $patient = \App\Domains\Patients\Models\Patient::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )->where(function ($q) use ($uuid, $hasRemoteUuid) {
                $q->where('uuid', $uuid);
                if ($hasRemoteUuid) {
                    $q->orWhere('remote_uuid', $uuid);
                }
            })->first();

            if (!$patient) {
                $this->deleteRemoteDirectly($uuid);
                return;
            }

            if ($patient->sync_status === 'pending_create') {
                // Confirmed live: create() always pushes a sync_queue
                // 'create' row with a full payload snapshot. Force-deleting
                // here without cancelling it left that row stuck pending
                // forever — SyncEngineService::processPendingDeletes()
                // never sees it (this patient never had sync_status
                // 'pending_delete'), and PatientSyncService::processItem()
                // just fails it repeatedly ("Local patient record not
                // found") since the local row this branch just deleted no
                // longer exists to build a create payload from. push() with
                // operation 'delete' against an existing 'create' item
                // cancels it outright (SyncQueueService Rule B) instead of
                // enqueueing a pointless delete for a patient that was
                // never on the server.
                $this->queueService->push('patient', $patient->uuid, 'delete');
                $patient->forceDelete();
                return;
            }

            $patient->update([
                'sync_status'       => 'pending_delete',
                'client_updated_at' => now(),
            ]);
            $patient->delete();

            $this->queueService->push('patient', $patient->uuid, 'delete');
        });
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
        if (!$this->isOfflineDevice()) {
            $this->local->restore($uuid);
            $this->local->update($uuid, ['sync_status' => 'synced']);
            return;
        }

        DB::transaction(function () use ($uuid) {
            $this->local->restore($uuid);
            $this->local->update($uuid, ['sync_status' => 'pending_update', 'client_updated_at' => now()]);
            $this->queueService->push('patient', $uuid, 'update');
        });
    }

    public function forceDelete(string $uuid): void
    {
        if (!$this->isOfflineDevice()) {
            \App\Domains\Patients\Models\Patient::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )->withTrashed()->where('uuid', $uuid)->first()?->forceDelete();
            return;
        }

        DB::transaction(function () use ($uuid) {
            $hasRemoteUuid = \Illuminate\Support\Facades\Schema::hasColumn('patients', 'remote_uuid');
            $patient = \App\Domains\Patients\Models\Patient::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )->withTrashed()->where(function ($q) use ($uuid, $hasRemoteUuid) {
                $q->where('uuid', $uuid);
                if ($hasRemoteUuid) {
                    $q->orWhere('remote_uuid', $uuid);
                }
            })->first();

            if (!$patient) {
                $this->deleteRemoteDirectly($uuid);
                return;
            }

            // Was previously immediate forceDelete() + queue push. That wiped
            // the local row before the server ever knew about it: if the
            // queue item was never processed (manual Sync button uses a
            // different consumer than the background SyncEngineService),
            // the delete never reached production, and the row was already
            // gone locally with nothing left to retry from. Mark
            // pending_delete instead, same as delete() above — the local
            // list already excludes pending_delete (paginated() filters it),
            // so it disappears from the UI immediately, while
            // SyncEngineService::processPendingDeletes() pushes the real
            // delete to the server and only then removes the row for good.
            if ($patient->sync_status === 'pending_create') {
                // Confirmed live: create() always pushes a sync_queue
                // 'create' row with a full payload snapshot. Force-deleting
                // here without cancelling it left that row stuck pending
                // forever — SyncEngineService::processPendingDeletes()
                // never sees it (this patient never had sync_status
                // 'pending_delete'), and PatientSyncService::processItem()
                // just fails it repeatedly ("Local patient record not
                // found") since the local row this branch just deleted no
                // longer exists to build a create payload from. push() with
                // operation 'delete' against an existing 'create' item
                // cancels it outright (SyncQueueService Rule B) instead of
                // enqueueing a pointless delete for a patient that was
                // never on the server.
                $this->queueService->push('patient', $patient->uuid, 'delete');
                $patient->forceDelete();
                return;
            }

            $patient->update([
                'sync_status'       => 'pending_delete',
                'client_updated_at' => now(),
            ]);
            if (!$patient->trashed()) {
                $patient->delete();
            }

            $this->queueService->push('patient', $patient->uuid, 'delete');
        });
    }
}
