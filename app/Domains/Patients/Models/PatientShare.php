<?php

namespace App\Domains\Patients\Models;

use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Model;

class PatientShare extends Model
{
    protected $fillable = [
        'patient_id', 'doctor_id', 'shared_by_id', 'access_level', 'expires_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function sharedBy()
    {
        return $this->belongsTo(User::class, 'shared_by_id');
    }
}
