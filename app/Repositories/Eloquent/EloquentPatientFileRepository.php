<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Patients\Models\Patient;

class EloquentPatientFileRepository implements PatientFileRepositoryInterface
{
    public function forPatient(string $patientUuid, ?int $limit = null): array
    {
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        $query = $patient->files()
            ->latest()
            ->select([
                'id', 'uuid', 'patient_id', 'title', 'file_name', 'mime_type',
                'size', 'category', 'date', 'created_at', 'updated_at', 'desc',
                'notes', 'tags',
                // FIX: Include columns required to compute url/thumbnail_url appended attributes
                'file_path', 'thumbnail_path', 'type', 'upload_status', 'sync_status',
            ]);

        // PERF: Optional LIMIT — prevents loading thousands of rows (and their
        // appended url/thumbnail_url attributes) for patients with large file
        // histories. The workspace only renders a window + stats counters.
        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get()->toArray(); // toArray() triggers $appends (url, thumbnail_url, name, extension)
    }

    /**
     * Cheap COUNT of a patient's files (for stats without loading the list).
     */
    public function countForPatient(string $patientUuid): int
    {
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        return $patient->files()->count();
    }

    public function find(string $uuid): ?array
    {
        $file = PatientFile::where('uuid', $uuid)->first();
        return $file?->toArray();
    }

    public function upload(string $patientUuid, array $file, array $data = []): array
    {
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        $file = $patient->files()->create($data);
        return $file->toArray();
    }

    public function delete(string $uuid): void
    {
        PatientFile::where('uuid', $uuid)->firstOrFail()->delete();
    }

    public function byCategory(string $patientUuid, string $categorySlug): array
    {
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        return $patient->files()
            ->where('category', $categorySlug)
            ->latest()
            ->get()
            ->toArray();
    }
}
