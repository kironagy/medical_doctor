<?php

namespace App\Http\Resources\Mobile;

use App\Domains\Media\Models\PatientFile;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin PatientFile */
class PatientFileResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,
            'patient_id' => $this->patient?->uuid ?? null,
            'uploaded_by_id' => $this->uploader?->uuid ?? null,
            'title' => $this->title,
            'desc' => $this->desc,
            'notes' => $this->notes,
            'tags' => $this->tags,
            'type' => $this->type,
            'category' => $this->category,
            'date' => $this->date,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'size' => (int) $this->size,
            'thumbnail_url' => $this->thumbnail_url,
            'url' => $this->url,
            'upload_status' => $this->upload_status,
            'client_updated_at' => $this->client_updated_at ? Carbon::parse($this->client_updated_at)->toISOString() : null,
            'created_at' => $this->created_at ? Carbon::parse($this->created_at)->toISOString() : null,
            'updated_at' => $this->updated_at ? Carbon::parse($this->updated_at)->toISOString() : null,
            'deleted_at' => $this->deleted_at ? Carbon::parse($this->deleted_at)->toISOString() : null,
            '_sync_action' => $this->trashed() ? 'deleted' : null,
        ];
    }
}
