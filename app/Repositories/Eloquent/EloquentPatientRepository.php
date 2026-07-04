<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Domains\Patients\Models\Patient;
use App\Domains\Media\Models\PatientFile;

class EloquentPatientRepository implements PatientRepositoryInterface
{
    public function all(): array
    {
        return Patient::latest()->get()->toArray();
    }

    public function paginated(int $perPage = 10, int $page = 1, ?string $status = null): array
    {
        $query = Patient::latest();
        
        if ($status === 'archived') {
            $query = Patient::onlyTrashed()->latest();
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
        $patient = Patient::where('uuid', $uuid)->first();
        return $patient?->toArray();
    }

    public function findByUuid(string $uuid): array
    {
        return Patient::where('uuid', $uuid)->firstOrFail()->toArray();
    }

    public function create(array $data): array
    {
        return Patient::create($data)->toArray();
    }

    public function update(string $uuid, array $data): array
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        $patient->update($data);
        return $patient->fresh()->toArray();
    }

    public function delete(string $uuid): void
    {
        Patient::where('uuid', $uuid)->firstOrFail()->delete();
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
