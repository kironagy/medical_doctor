<?php

namespace App\Domains\Auth\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DoctorIsolationScope implements Scope
{
 public function apply(Builder $builder, Model $model)
 {
     // In NativePHP (mobile), the local SQLite only contains the logged-in user's data.
     // Applying isolation would incorrectly filter out every record (since remote user IDs
     // don't always map 1:1 to local SQLite IDs after sync). Skip the scope here.
     if (app()->runningInConsole() && !app()->runningUnitTests()) {
         return;
     }
     if (\App\Helpers\NativePhp::isRunning()) {
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
                // For related tables (patient_files, patient_visits, patient_notes) that have patient_id:
                // Use direct whereIn on patient_id instead of whereHas('patient', ...) to avoid
                // nested correlated subqueries which cause issues on SQLite
                $builder->whereIn('patient_id', function ($query) use ($user) {
                    // Query patients directly without applying DoctorIsolationScope again
                    $query->select('id')
                          ->from('patients')
                          ->where('primary_doctor_id', $user->id);
                })->orWhereIn('patient_id', function ($query) use ($user) {
                    // Also include patients shared with this doctor
                    $query->select('patient_id')
                          ->from('patient_shares')
                          ->where('doctor_id', $user->id)
                          ->where(function($q2) {
                              $q2->whereNull('expires_at')->orWhere('expires_at', '>', now());
                          });
                });
            }
        }
    }
}
