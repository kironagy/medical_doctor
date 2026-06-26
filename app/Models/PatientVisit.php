<?php

namespace App\Models;

use App\Models\Concerns\HasSyncIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PatientVisit extends Model
{
    use HasSyncIdentity, SoftDeletes;

    protected $fillable = [
        'uuid',
        'patient_id',
        'visit_type',
        'visit_type_custom',
        'reason',
        'reason_custom',
        'visit_date',
        'visit_time',
        'session_details',
        'diagnosis',
        'prescription',
        'next_visit_date',
        'cost',
        'client_updated_at',
    ];

    protected $casts = [
        'session_details' => 'array',
        'visit_date'      => 'date:Y-m-d',
        'next_visit_date' => 'date:Y-m-d',
        'client_updated_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    // Computed: display label for visit type
    public function getVisitTypeLabelAttribute(): string
    {
        return $this->visit_type === 'غيره' && $this->visit_type_custom
            ? $this->visit_type_custom
            : $this->visit_type;
    }

    // Computed: display label for reason
    public function getReasonLabelAttribute(): string
    {
        return $this->reason === 'غيره' && $this->reason_custom
            ? $this->reason_custom
            : $this->reason;
    }
}
