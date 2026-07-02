<?php

namespace App\Http\Resources\Mobile;

use App\Domains\Patients\Models\PatientVisit;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin PatientVisit */
class PatientVisitResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,
            'patient_id' => $this->patient?->uuid ?? null,
            'visit_type' => $this->visit_type,
            'visit_type_custom' => $this->visit_type_custom,
            'reason' => $this->reason,
            'reason_custom' => $this->reason_custom,
            'visit_date' => $this->visit_date,
            'visit_time' => $this->visit_time,
            'session_details' => $this->session_details,
            'diagnosis' => $this->diagnosis,
            'prescription' => $this->prescription,
            'next_visit_date' => $this->next_visit_date,
            'cost' => (float) $this->cost,
            'client_updated_at' => $this->client_updated_at ? Carbon::parse($this->client_updated_at)->toISOString() : null,
            'created_at' => $this->created_at ? Carbon::parse($this->created_at)->toISOString() : null,
            'updated_at' => $this->updated_at ? Carbon::parse($this->updated_at)->toISOString() : null,
            'deleted_at' => $this->deleted_at ? Carbon::parse($this->deleted_at)->toISOString() : null,
            '_sync_action' => $this->trashed() ? 'deleted' : null,
        ];
    }
}
