<?php

namespace App\Models;

use App\Models\Concerns\HasSyncIdentity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    use HasFactory, HasSyncIdentity, SoftDeletes;

    protected $fillable = ['uuid', 'code', 'name', 'phone', 'address', 'diagnosis', 'client_updated_at'];

    protected $casts = [
        'client_updated_at' => 'datetime',
    ];

    public function files()
    {
        return $this->hasMany(PatientFile::class);
    }

    public function visits()
    {
        return $this->hasMany(PatientVisit::class);
    }
}
