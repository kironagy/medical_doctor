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
                      // ── Patients created offline without a doctor — show only
                      // to the doctor who CREATED the patient. This replaces the
                      // previous orWhereNull('primary_doctor_id') which exposed
                      // ALL unowned patients to ALL doctors (SEC-001).
                      ->orWhere(function ($sub) use ($user) {
                          $sub->whereNull('primary_doctor_id')
                              ->where('created_by_id', $user->id);
                      })
                      // ── Patients shared with this doctor via patient_shares
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
                      ->orWhere(function ($sub) use ($user) {
                          $sub->whereNull('primary_doctor_id')
                              ->where('created_by_id', $user->id);
                      })
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
