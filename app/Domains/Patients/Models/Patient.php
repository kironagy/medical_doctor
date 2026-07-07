<?php

namespace App\Domains\Patients\Models;

use App\Domains\Users\Models\User;
use App\Domains\Media\Models\PatientFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Patient extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'primary_doctor_id', 'code', 'name', 'phone', 'email', 'address', 'created_by_id', 'diagnosis',
        'client_updated_at', 'date_of_birth', 'gender', 'blood_group', 'weight', 'height',
        'allergies', 'chronic_diseases', 'medical_status', 'medical_record_number',
    ];

    protected $casts = [
        'client_updated_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Domains\Auth\Scopes\DoctorIsolationScope);

        static::creating(function ($model) {
            if (empty($model->uuid)) $model->uuid = (string) \Illuminate\Support\Str::uuid();
        });
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->withTrashed()->where($field ?? $this->getRouteKeyName(), $value)->firstOrFail();
    }

    public function getAgeAttribute(): ?int
    {
        if (!$this->date_of_birth) return null;
        return now()->diffInYears($this->date_of_birth);
    }

    public function primaryDoctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_doctor_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(PatientVisit::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(PatientShare::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(PatientFile::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(PatientNote::class);
    }
}
