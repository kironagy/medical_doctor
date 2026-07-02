<?php

namespace App\Http\Resources\Mobile;

use App\Domains\Patients\Models\PatientNote;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin PatientNote */
class PatientNoteResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,
            'patient_id' => $this->patient?->uuid ?? null,
            'author_id' => $this->author?->uuid ?? null,
            'category' => $this->category,
            'content' => $this->content,
            'client_updated_at' => $this->client_updated_at ? Carbon::parse($this->client_updated_at)->toISOString() : null,
            'created_at' => $this->created_at ? Carbon::parse($this->created_at)->toISOString() : null,
            'updated_at' => $this->updated_at ? Carbon::parse($this->updated_at)->toISOString() : null,
            'deleted_at' => $this->deleted_at ? Carbon::parse($this->deleted_at)->toISOString() : null,
            '_sync_action' => $this->trashed() ? 'deleted' : null,
        ];
    }
}
