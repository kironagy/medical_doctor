<?php

namespace App\Domains\Patients\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'diagnosis' => $this->diagnosis,
            'primary_doctor_id' => $this->primary_doctor_id,
            'created_by_id' => $this->created_by_id,
            'date_of_birth' => $this->date_of_birth,
            'gender' => $this->gender,
            'blood_group' => $this->blood_group,
            'weight' => $this->weight,
            'height' => $this->height,
            'allergies' => $this->allergies,
            'chronic_diseases' => $this->chronic_diseases,
            'medical_status' => $this->medical_status,
            'medical_record_number' => $this->medical_record_number,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'client_updated_at' => $this->client_updated_at?->toIso8601String(),
            
            // Loaded Relations
            'primary_doctor' => $this->whenLoaded('primaryDoctor', function () {
                return [
                    'id' => $this->primaryDoctor->id,
                    'name' => $this->primaryDoctor->name,
                ];
            }),
            'visits' => $this->whenLoaded('visits'),
            'shares' => $this->whenLoaded('shares'),
            'files' => $this->whenLoaded('files'),
        ];
    }
}
