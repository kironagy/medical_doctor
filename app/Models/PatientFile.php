<?php

namespace App\Models;

use App\Models\Concerns\HasSyncIdentity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PatientFile extends Model
{
    use HasFactory, HasSyncIdentity, SoftDeletes;

    protected $fillable = ['uuid', 'patient_id', 'title', 'desc', 'type', 'category', 'date', 'file_name', 'file_path', 'thumbnail_path', 'upload_status', 'data', 'client_updated_at'];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'client_updated_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }
}
