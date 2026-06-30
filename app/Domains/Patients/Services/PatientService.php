<?php

namespace App\Domains\Patients\Services;

use App\Domains\Patients\Models\Patient;
use App\Domains\Users\Models\User;

class PatientService
{
    public function createForDoctor(User $doctor, array $data): Patient
    {
        $data['primary_doctor_id'] = $doctor->id;
        return Patient::create($data);
    }

    public function transferOwnership(Patient $patient, User $newPrimaryDoctor): Patient
    {
        $patient->update([
            'primary_doctor_id' => $newPrimaryDoctor->id
        ]);
        
        return $patient;
    }
}
