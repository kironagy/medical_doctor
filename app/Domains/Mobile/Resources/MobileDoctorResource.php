<?php

namespace App\Domains\Mobile\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileDoctorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'specialization' => $this->specialization,
            'code' => $this->code,
            'avatar_url' => $this->avatar_url,
            'patient_count' => $this->patient_count ?? null,
        ];
    }
}
