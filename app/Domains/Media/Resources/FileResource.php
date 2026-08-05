<?php

namespace App\Domains\Media\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'patient_id' => $this->patient_id,
            'patient_uuid' => $this->whenLoaded('patient', fn () => $this->patient->uuid),
            'title' => $this->title,
            'description' => $this->desc,
            'type' => $this->type,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'category' => $this->category,
            'date' => $this->date?->format('Y-m-d'),
            'file_name' => $this->file_name,
            'url' => $this->url,
            'thumbnail_url' => $this->thumbnail_url,
            'upload_status' => $this->upload_status, // Always 'ready' with direct uploads
            'created_at' => $this->created_at?->toIso8601String(),

            'uploader' => $this->whenLoaded('uploader', function () {
                return [
                    'id' => $this->uploader->id,
                    'name' => $this->uploader->name,
                ];
            }),
        ];
    }
}
