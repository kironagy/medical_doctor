<?php

namespace App\Repositories;

use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Repositories\Eloquent\EloquentPatientRepository;
use App\Domains\Sync\Services\SyncQueueService;
use Illuminate\Support\Facades\DB;

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
                return;
            }

            if ($patient->sync_status === 'pending_create') {
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
                return;
            }

            $patient->forceDelete();
            $this->queueService->push('patient', $patient->uuid, 'delete');
        });
    }
}
