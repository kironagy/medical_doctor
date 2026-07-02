<?php

namespace App\Domains\Mobile\Services;

use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientVisit;
use App\Domains\Patients\Models\PatientNote;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Media\Models\FileCategory;
use Illuminate\Support\Collection;

class SyncEngine
{
    public function pullChanges(int $userId, ?string $lastSyncAt, array $entities): array
    {
        $data = [];

        foreach ($entities as $entity) {
            $data[$entity] = match ($entity) {
                'patients' => $this->getPatients($userId, $lastSyncAt),
                'files' => $this->getFiles($userId, $lastSyncAt),
                'visits' => $this->getVisits($userId, $lastSyncAt),
                'notes' => $this->getNotes($userId, $lastSyncAt),
                'categories' => FileCategory::all()->toArray(),
                default => [],
            };
        }

        return $data;
    }

    public function getPatients(int $userId, ?string $lastSyncAt): Collection
    {
        $query = Patient::where('primary_doctor_id', $userId)->withTrashed();
        if ($lastSyncAt) $query->where('updated_at', '>', $lastSyncAt);
        return $query->get();
    }

    public function getFiles(int $userId, ?string $lastSyncAt): Collection
    {
        $patientIds = Patient::where('primary_doctor_id', $userId)->select('id');
        $query = PatientFile::whereIn('patient_id', $patientIds)->withTrashed();
        if ($lastSyncAt) $query->where('updated_at', '>', $lastSyncAt);
        return $query->get();
    }

    public function getVisits(int $userId, ?string $lastSyncAt): Collection
    {
        $patientIds = Patient::where('primary_doctor_id', $userId)->select('id');
        $query = PatientVisit::whereIn('patient_id', $patientIds)->withTrashed();
        if ($lastSyncAt) $query->where('updated_at', '>', $lastSyncAt);
        return $query->get();
    }

    public function getNotes(int $userId, ?string $lastSyncAt): Collection
    {
        $patientIds = Patient::where('primary_doctor_id', $userId)->select('id');
        $query = PatientNote::whereIn('patient_id', $patientIds);
        if ($lastSyncAt) $query->where('updated_at', '>', $lastSyncAt);
        return $query->get();
    }
}
