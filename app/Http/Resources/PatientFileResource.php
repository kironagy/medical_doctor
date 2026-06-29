<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'patient_id' => $this->patient_id,
            'title' => $this->title,
            'desc' => $this->desc,
            'type' => $this->type,
            'category' => $this->category,
            'date' => $this->date?->format('Y-m-d'),
            'file_name' => $this->file_name,
            'file_path' => $this->file_path,
            'file_url' => $this->file_path ? url($this->file_path) : null,
            'stream_url' => ($this->file_path && str_contains($this->file_path, '.m3u8')) ? url($this->file_path) : null,
            'thumbnail_path' => $this->thumbnail_path,
            'upload_status' => $this->upload_status,
            'duration' => $this->duration,
            'resolution' => $this->resolution,
            'processing_progress' => $this->processing_progress,
            'processing_stage' => $this->processing_stage,
            'data' => $this->data,
            'client_updated_at' => $this->client_updated_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
