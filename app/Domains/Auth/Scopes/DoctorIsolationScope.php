<?php

namespace App\Domains\Auth\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class DoctorIsolationScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        if (app()->runningInConsole() && !app()->runningUnitTests()) {
            return;
        }

        $user = Auth::user();
        if (!$user) return;

        // Skip isolation for admins
        if ($user->hasRole(['super-admin', 'admin'])) {
            return;
        }

        // Apply isolation for doctors
        if ($user->hasRole('doctor')) {
            $table = $model->getTable();
            
            if ($table === 'patients') {
                $builder->where(function ($q) use ($user) {
                    $q->where('primary_doctor_id', $user->id)
                      // ── NEW: Include patients with null primary_doctor_id
                      // Patients created via the sync engine without a valid
                      // Bearer token may have primary_doctor_id = NULL. Without
                      // this fallback, those patients are invisible to all doctors
                      // and can never be claimed or edited.
                      ->orWhereNull('primary_doctor_id')
                      ->orWhereIn('id', function ($query) use ($user) {
                          $query->select('patient_id')
                                ->from('patient_shares')
                                ->where('doctor_id', $user->id)
                                ->where(function($q2) {
                                    $q2->whereNull('expires_at')->orWhere('expires_at', '>', now());
                                });
                      });
                });
            } else {
                // For tables like patient_files, patient_visits which have a patient_id
                $builder->whereHas('patient', function ($q) use ($user) {
                    $q->where('primary_doctor_id', $user->id)
                      // ── NEW: Include patients with null primary_doctor_id
                      // Same reasoning as above — sync-created patients without
                      // a doctor ID must still be accessible so their files,
                      // visits, and notes can be viewed by the doctor.
                      ->orWhereNull('primary_doctor_id')
                      ->orWhereIn('id', function ($query) use ($user) {
                          $query->select('patient_id')
                                ->from('patient_shares')
                                ->where('doctor_id', $user->id)
                                ->where(function($q2) {
                                    $q2->whereNull('expires_at')->orWhere('expires_at', '>', now());
                                });
                      });
                });
            }
        }
    }
}
