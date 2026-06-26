<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientVisitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'patient_id' => $this->patient_id,
            'visit_type' => $this->visit_type,
            'visit_type_custom' => $this->visit_type_custom,
            'visit_type_label' => $this->visit_type_label,
            'reason' => $this->reason,
            'reason_custom' => $this->reason_custom,
            'reason_label' => $this->reason_label,
            'visit_date' => $this->visit_date?->format('Y-m-d'),
            'visit_time' => $this->visit_time,
            'session_details' => $this->session_details ?? [],
            'diagnosis' => $this->diagnosis,
            'prescription' => $this->prescription,
            'next_visit_date' => $this->next_visit_date?->format('Y-m-d'),
            'cost' => $this->cost,
            'client_updated_at' => $this->client_updated_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
