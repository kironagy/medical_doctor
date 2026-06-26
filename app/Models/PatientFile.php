<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PatientFile extends Model
{
    use HasFactory;

    protected $fillable = ['patient_id', 'title', 'desc', 'type', 'category', 'date', 'file_name', 'file_path', 'data'];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }
}
