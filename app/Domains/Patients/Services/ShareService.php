<?php

namespace App\Domains\Patients\Services;

use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientShare;
use App\Domains\Users\Models\User;
use App\Domains\ActivityLogs\Services\ActivityLogger;

class ShareService
{
    public function __construct(
        private readonly ActivityLogger $logger
    ) {}

    public function sharePatient(Patient $patient, User $doctor, User $sharedBy, string $accessLevel = 'read', ?string $expiresAt = null): PatientShare
    {
        $share = PatientShare::updateOrCreate(
            ['patient_id' => $patient->id, 'doctor_id' => $doctor->id],
            [
                'shared_by_id' => $sharedBy->id,
                'access_level' => $accessLevel,
                'expires_at' => $expiresAt
            ]
        );

        $this->logger->log(
            'share_patient', 
            'Patient', 
            $patient->uuid, 
            ['shared_with_doctor_id' => $doctor->id, 'access_level' => $accessLevel], 
            $sharedBy
        );

        return $share;
    }
    
    public function revokeAccess(Patient $patient, User $doctor, User $revokedBy): void
    {
        PatientShare::where('patient_id', $patient->id)
            ->where('doctor_id', $doctor->id)
            ->delete();

        $this->logger->log(
            'revoke_patient_share', 
            'Patient', 
            $patient->uuid, 
            ['revoked_doctor_id' => $doctor->id], 
            $revokedBy
        );
    }
}
