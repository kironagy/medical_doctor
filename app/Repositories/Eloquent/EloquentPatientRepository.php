<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Domains\Patients\Models\Patient;
use App\Domains\Media\Models\PatientFile;

class EloquentPatientRepository implements PatientRepositoryInterface
{
    public function all(): array
    {
        return Patient::withoutGlobalScope(
            \App\Domains\Auth\Scopes\DoctorIsolationScope::class
        )->where(function ($q) {
            $q->whereNull('sync_status')->orWhere('sync_status', '!=', 'pending_delete');
        })->latest()->get()->toArray();
    }

    public function paginated(int $perPage = 10, int $page = 1, ?string $status = null): array
    {
        $query = Patient::withoutGlobalScope(
            \App\Domains\Auth\Scopes\DoctorIsolationScope::class
        )->latest();
        
        if ($status === 'archived') {
            $query = Patient::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )->onlyTrashed()->latest();
        } else {
            $query->where(function ($q) {
                $q->whereNull('sync_status')->orWhere('sync_status', '!=', 'pending_delete');
            });
        }
        
        $paginator = $query->paginate(
            perPage: $perPage,
            page: $page
        );
        
        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ]
        ];
    }

    public function find(string $uuid): ?array
    {
        $patient = Patient::withoutGlobalScope(
            \App\Domains\Auth\Scopes\DoctorIsolationScope::class
        )->where(function ($q) use ($uuid) {
            $q->where('uuid', $uuid)->orWhere('remote_uuid', $uuid);
        })->first();
        return $patient?->toArray();
    }

    public function findByUuid(string $uuid): array
    {
        $patient = Patient::withoutGlobalScope(
            \App\Domains\Auth\Scopes\DoctorIsolationScope::class
        )->where(function ($q) use ($uuid) {
            $q->where('uuid', $uuid)->orWhere('remote_uuid', $uuid);
        })->first();

        if (!$patient) {
            throw new \RuntimeException('Patient not found: ' . $uuid);
        }

        return $patient->toArray();
    }

    public function create(array $data): array
    {
        $tf = '/data/local/tmp/np_traces.txt';
        @file_put_contents($tf, now()->format('H:i:s.v') . ' P6h Eloquent::create() ENTERED name=' . ($data['name'] ?? 'none') . "\n", FILE_APPEND | LOCK_EX);
        try {
            $patient = Patient::create($data);
            @file_put_contents($tf, now()->format('H:i:s.v') . ' P6i Patient::create() SUCCESS uuid=' . $patient->uuid . "\n", FILE_APPEND | LOCK_EX);
            return $patient->toArray();
        } catch (\Throwable $e) {
            @file_put_contents($tf, now()->format('H:i:s.v') . ' P6j THREW ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND | LOCK_EX);
            throw $e;
        }
    }

    public function update(string $uuid, array $data): array
    {
        $patient = Patient::withoutGlobalScope(
            \App\Domains\Auth\Scopes\DoctorIsolationScope::class
        )->where(function ($q) use ($uuid) {
            $q->where('uuid', $uuid)->orWhere('remote_uuid', $uuid);
        })->firstOrFail();
        $patient->update($data);
        return $patient->fresh()->toArray();
    }

    public function delete(string $uuid): void
    {
        $patient = Patient::withoutGlobalScope(
            \App\Domains\Auth\Scopes\DoctorIsolationScope::class
        )->where(function ($q) use ($uuid) {
            $q->where('uuid', $uuid)->orWhere('remote_uuid', $uuid);
        })->first();
        if ($patient) {
            $patient->delete();
        }
    }

    public function search(string $term): array
    {
        return Patient::where('name', 'like', "%{$term}%")
            ->orWhere('code', 'like', "%{$term}%")
            ->orWhere('phone', 'like', "%{$term}%")
            ->orWhere('diagnosis', 'like', "%{$term}%")
            ->latest()
            ->get()
            ->toArray();
    }

    public function shared(int $userId): array
    {
        return Patient::where('primary_doctor_id', '!=', $userId)
            ->latest()
            ->get()
            ->toArray();
    }

    public function stats(): array
    {
        return [
            'total_patients' => Patient::count(),
            'recent_files' => PatientFile::count(),
            'active_shares' => \Illuminate\Support\Facades\DB::table('patient_shares')->count(),
        ];
    }

    public function recent(int $limit): array
    {
        return Patient::latest()->take($limit)->get()->toArray();
    }

    public function withTrashed(): array
    {
        return Patient::withTrashed()->latest()->get()->toArray();
    }

    public function restore(string $uuid): void
    {
        Patient::withTrashed()->where('uuid', $uuid)->firstOrFail()->restore();
    }

    public function forceDelete(string $uuid): void
    {
        Patient::withTrashed()->where('uuid', $uuid)->firstOrFail()->forceDelete();
    }
}
