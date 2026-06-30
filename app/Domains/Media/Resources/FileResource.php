<?php

namespace App\Domains\Media\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'uuid'           => $this->uuid,
            'patient_id'     => $this->patient_id,
            'title'          => $this->title,
            'description'    => $this->desc,
            'type'           => $this->type,
            'mime_type'      => $this->mime_type,
            'size'           => $this->size,
            'category'       => $this->category,
            'date'           => $this->date?->format('Y-m-d'),
            'file_name'      => $this->file_name,
            // Served through the authenticated API endpoint (supports Range requests).
            'url'            => $this->url,
            // HLS is no longer generated; field kept for API compatibility (always null now).
            'hls_url'        => null,
            'thumbnail_url'  => $this->thumbnail_url,
            'upload_status'  => $this->upload_status,
            'video_metadata' => $this->video_metadata,
            // Timing data for the pipeline performance report.
            'processing_times' => $this->processing_times,
            'created_at'     => $this->created_at?->toIso8601String(),

            'uploader' => $this->whenLoaded('uploader', function () {
                return [
                    'id'   => $this->uploader->id,
                    'name' => $this->uploader->name,
                ];
            }),
        ];
    }
}
