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
            'title' => $this->title,
            'description' => $this->desc,
            'type' => $this->type,
            'category' => $this->category,
            'date' => $this->date?->format('Y-m-d'),
            'file_name' => $this->file_name,
            'file_url' => $this->file_path ? url('storage/' . $this->file_path) : null,
            'thumbnail_url' => $this->thumbnail_path ? url('storage/' . $this->thumbnail_path) : null,
            'upload_status' => $this->upload_status,
            'video_metadata' => $this->video_metadata,
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
