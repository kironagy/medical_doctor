<?php

namespace App\Domains\Patients\Models;

use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PatientShare extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'patient_id', 'doctor_id', 'shared_by_id', 'access_level', 'expires_at', 'client_updated_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'client_updated_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) $model->uuid = (string) Str::uuid();
        });
    }

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
