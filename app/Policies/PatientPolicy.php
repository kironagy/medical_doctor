<?php

namespace App\Policies;

use App\Domains\Patients\Models\Patient;
use App\Domains\Users\Models\User;
use App\Domains\Patients\Models\PatientShare;

class PatientPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['super-admin', 'admin', 'doctor']);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Patient $patient): bool
    {
        if ($user->hasRole(['super-admin', 'admin'])) return true;
        
        if ($patient->primary_doctor_id === $user->id) return true;

        $share = PatientShare::where('patient_id', $patient->id)
            ->where('doctor_id', $user->id)
            ->where(function($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })->first();

        return $share !== null;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(['super-admin', 'admin', 'doctor']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Patient $patient): bool
    {
        if ($user->hasRole(['super-admin', 'admin'])) return true;
        
        if ($patient->primary_doctor_id === $user->id) return true;

        $share = PatientShare::where('patient_id', $patient->id)
            ->where('doctor_id', $user->id)
            ->whereIn('access_level', ['read_write', 'full'])
            ->where(function($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })->first();

        return $share !== null;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Patient $patient): bool
    {
        if ($user->hasRole(['super-admin', 'admin'])) return true;
        
        return $patient->primary_doctor_id === $user->id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Patient $patient): bool
    {
        return $this->delete($user, $patient);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Patient $patient): bool
    {
        return $this->delete($user, $patient);
    }

    /**
     * Check if user can share this patient.
     */
    public function share(User $user, Patient $patient): bool
    {
        if ($user->hasRole(['super-admin', 'admin'])) return true;
        return $patient->primary_doctor_id === $user->id;
    }
}
