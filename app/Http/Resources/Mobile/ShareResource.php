<?php

namespace App\Http\Resources\Mobile;

use App\Domains\Patients\Models\PatientShare;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin PatientShare */
class ShareResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,
            'patient_id' => $this->patient?->uuid ?? null,
            'doctor_id' => $this->doctor?->uuid ?? null,
            'shared_by_id' => $this->sharedBy?->uuid ?? null,
            'access_level' => $this->access_level,
            'expires_at' => $this->expires_at ? Carbon::parse($this->expires_at)->toISOString() : null,
            'client_updated_at' => $this->client_updated_at ? Carbon::parse($this->client_updated_at)->toISOString() : null,
            'created_at' => $this->created_at ? Carbon::parse($this->created_at)->toISOString() : null,
            'updated_at' => $this->updated_at ? Carbon::parse($this->updated_at)->toISOString() : null,
            'deleted_at' => $this->deleted_at ? Carbon::parse($this->deleted_at)->toISOString() : null,
            '_sync_action' => $this->trashed() ? 'deleted' : null,
        ];
    }
}
