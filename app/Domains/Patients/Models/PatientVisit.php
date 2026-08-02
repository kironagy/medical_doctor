<?php

namespace App\Domains\Patients\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PatientVisit extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'patient_id', 'visit_type', 'visit_type_custom', 'reason', 'reason_custom',
        'visit_date', 'visit_time', 'session_details', 'diagnosis', 'prescription',
        'next_visit_date', 'cost',
        // Offline sync columns
        'sync_status', 'remote_uuid', 'client_updated_at',
    ];

    protected $casts = [
        'visit_date'       => 'date',
        'next_visit_date'  => 'date',
        'session_details'  => 'array',
        'cost'             => 'decimal:2',
        'client_updated_at'=> 'datetime',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Domains\Auth\Scopes\DoctorIsolationScope);

        static::creating(function ($model) {
            if (empty($model->uuid)) $model->uuid = (string) \Illuminate\Support\Str::uuid();
        });
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }
}
