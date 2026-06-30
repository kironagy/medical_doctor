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
            'address' => $this->address,
            'diagnosis' => $this->diagnosis,
            'primary_doctor_id' => $this->primary_doctor_id,
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
        ];
    }
}
