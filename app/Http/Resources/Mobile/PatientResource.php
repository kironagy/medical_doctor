<?php

namespace App\Http\Resources\Mobile;

use App\Domains\Patients\Models\Patient;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin Patient */
class PatientResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'diagnosis' => $this->diagnosis,
            'date_of_birth' => $this->date_of_birth,
            'gender' => $this->gender,
            'blood_group' => $this->blood_group,
            'weight' => $this->weight,
            'height' => $this->height,
            'allergies' => $this->allergies,
            'chronic_diseases' => $this->chronic_diseases,
            'medical_status' => $this->medical_status,
            'medical_record_number' => $this->medical_record_number,
            'code' => $this->code,
            'primary_doctor_id' => $this->primaryDoctor?->uuid ?? null,
            'client_updated_at' => $this->client_updated_at ? Carbon::parse($this->client_updated_at)->toISOString() : null,
            'created_at' => $this->created_at ? Carbon::parse($this->created_at)->toISOString() : null,
            'updated_at' => $this->updated_at ? Carbon::parse($this->updated_at)->toISOString() : null,
            'deleted_at' => $this->deleted_at ? Carbon::parse($this->deleted_at)->toISOString() : null,
            '_sync_action' => $this->trashed() ? 'deleted' : null,
            'visits' => PatientVisitResource::collection($this->whenLoaded('visits')),
            'notes' => PatientNoteResource::collection($this->whenLoaded('notes')),
            'files' => PatientFileResource::collection($this->whenLoaded('files')),
            'shares' => ShareResource::collection($this->whenLoaded('shares')),
            'primaryDoctor' => $this->whenLoaded('primaryDoctor', function () {
                return [
                    'uuid' => $this->primaryDoctor?->uuid,
                    'name' => $this->primaryDoctor?->name,
                    'email' => $this->primaryDoctor?->email,
                    'phone' => $this->primaryDoctor?->phone,
                    'specialization' => $this->primaryDoctor?->specialization,
                ];
            }),
        ];
    }
}
