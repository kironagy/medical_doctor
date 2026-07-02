<?php

namespace App\Domains\Mobile\Services;

use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientVisit;
use App\Domains\Patients\Models\PatientNote;
use App\Domains\Patients\Models\PatientShare;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Media\Models\FileCategory;
use App\Domains\Users\Models\User;
use App\Http\Resources\Mobile\PatientSyncResource;
use App\Http\Resources\Mobile\PatientVisitResource;
use App\Http\Resources\Mobile\PatientNoteResource;
use App\Http\Resources\Mobile\PatientFileResource;
use App\Http\Resources\Mobile\CategoryResource;
use App\Http\Resources\Mobile\ShareResource;
use App\Http\Resources\Mobile\UserResource;
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
                'categories' => $this->getCategories($lastSyncAt),
                'shares' => $this->getShares($userId, $lastSyncAt),
                'doctors' => $this->getDoctors($lastSyncAt),
                default => [],
            };
        }

        return $data;
    }

    public function getPatients(int $userId, ?string $lastSyncAt): array
    {
        $query = Patient::where('primary_doctor_id', $userId)
            ->withTrashed()
            ->where(function ($q) use ($lastSyncAt) {
                if ($lastSyncAt) {
                    $q->where('updated_at', '>', $lastSyncAt)
                      ->orWhere('deleted_at', '>', $lastSyncAt)
                      ->orWhere('client_updated_at', '>', $lastSyncAt);
                }
            });

        return PatientSyncResource::collection($query->get())->resolve();
    }

    public function getFiles(int $userId, ?string $lastSyncAt): array
    {
        $patientIds = Patient::where('primary_doctor_id', $userId)->select('id');
        $sharedIds = PatientShare::where('doctor_id', $userId)
            ->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', now()); })
            ->select('patient_id');

        $query = PatientFile::whereIn('patient_id', $patientIds->union($sharedIds))
            ->withTrashed()
            ->where(function ($q) use ($lastSyncAt) {
                if ($lastSyncAt) {
                    $q->where('updated_at', '>', $lastSyncAt)
                      ->orWhere('deleted_at', '>', $lastSyncAt)
                      ->orWhere('client_updated_at', '>', $lastSyncAt);
                }
            });

        return PatientFileResource::collection($query->get())->resolve();
    }

    public function getVisits(int $userId, ?string $lastSyncAt): array
    {
        $patientIds = Patient::where('primary_doctor_id', $userId)->select('id');
        $sharedIds = PatientShare::where('doctor_id', $userId)
            ->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', now()); })
            ->select('patient_id');

        $query = PatientVisit::whereIn('patient_id', $patientIds->union($sharedIds))
            ->withTrashed()
            ->where(function ($q) use ($lastSyncAt) {
                if ($lastSyncAt) {
                    $q->where('updated_at', '>', $lastSyncAt)
                      ->orWhere('deleted_at', '>', $lastSyncAt)
                      ->orWhere('client_updated_at', '>', $lastSyncAt);
                }
            });

        return PatientVisitResource::collection($query->get())->resolve();
    }

    public function getNotes(int $userId, ?string $lastSyncAt): array
    {
        $patientIds = Patient::where('primary_doctor_id', $userId)->select('id');
        $sharedIds = PatientShare::where('doctor_id', $userId)
            ->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', now()); })
            ->select('patient_id');

        $query = PatientNote::whereIn('patient_id', $patientIds->union($sharedIds))
            ->withTrashed()
            ->where(function ($q) use ($lastSyncAt) {
                if ($lastSyncAt) {
                    $q->where('updated_at', '>', $lastSyncAt)
                      ->orWhere('deleted_at', '>', $lastSyncAt)
                      ->orWhere('client_updated_at', '>', $lastSyncAt);
                }
            });

        return PatientNoteResource::collection($query->get())->resolve();
    }

    public function getCategories(?string $lastSyncAt): array
    {
        $query = FileCategory::withTrashed()
            ->where(function ($q) use ($lastSyncAt) {
                if ($lastSyncAt) {
                    $q->where('updated_at', '>', $lastSyncAt)
                      ->orWhere('deleted_at', '>', $lastSyncAt)
                      ->orWhere('client_updated_at', '>', $lastSyncAt);
                }
            });

        return CategoryResource::collection($query->get())->resolve();
    }

    public function getShares(int $userId, ?string $lastSyncAt): array
    {
        $query = PatientShare::where(function ($q) use ($userId) {
                $q->where('doctor_id', $userId)
                  ->orWhereHas('patient', function ($pq) use ($userId) {
                      $pq->where('primary_doctor_id', $userId);
                  });
            })
            ->withTrashed()
            ->where(function ($q) use ($lastSyncAt) {
                if ($lastSyncAt) {
                    $q->where('updated_at', '>', $lastSyncAt)
                      ->orWhere('deleted_at', '>', $lastSyncAt)
                      ->orWhere('client_updated_at', '>', $lastSyncAt);
                }
            });

        return ShareResource::collection($query->get())->resolve();
    }

    public function getDoctors(?string $lastSyncAt): array
    {
        $query = User::where('role', 'doctor')
            ->where(function ($q) use ($lastSyncAt) {
                if ($lastSyncAt) {
                    $q->where('updated_at', '>', $lastSyncAt)
                      ->orWhere('client_updated_at', '>', $lastSyncAt);
                }
            });

        return UserResource::collection($query->get())->resolve();
    }
}
