<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientVisit;
use App\Domains\Patients\Models\PatientNote;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Media\Models\FileCategory;
use App\Http\Resources\Mobile\PatientSyncResource;
use App\Http\Resources\Mobile\PatientResource;
use App\Http\Resources\Mobile\PatientFileResource;
use App\Http\Resources\Mobile\PatientVisitResource;
use App\Http\Resources\Mobile\PatientNoteResource;
use App\Http\Resources\Mobile\CategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function pull(Request $request): JsonResponse
    {
        $request->validate([
            'last_sync_at' => 'nullable|date',
            'entities' => 'required|array',
            'entities.*' => 'string|in:patients,files,visits,notes,categories',
        ]);

        $user = $request->user();
        $lastSync = $request->last_sync_at;
        $entities = $request->entities;

        $data = [];

        if (in_array('patients', $entities)) {
            $data['patients'] = $this->getPatients($user, $lastSync);
        }

        if (in_array('files', $entities)) {
            $data['files'] = $this->getFiles($user, $lastSync);
        }

        if (in_array('visits', $entities)) {
            $data['visits'] = $this->getVisits($user, $lastSync);
        }

        if (in_array('notes', $entities)) {
            $data['notes'] = $this->getNotes($user, $lastSync);
        }

        if (in_array('categories', $entities)) {
            $data['categories'] = FileCategory::all();
        }

        return response()->json([
            'data' => $data,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function push(Request $request): JsonResponse
    {
        $request->validate([
            'patients' => 'nullable|array',
            'patients.*.uuid' => 'required|string',
            'patients.*.name' => 'required|string',
            'patients.*.phone' => 'nullable|string',
            'patients.*.client_updated_at' => 'required|date',

            'visits' => 'nullable|array',
            'visits.*.uuid' => 'required|string',
            'visits.*.patient_uuid' => 'required|string',
            'visits.*.client_updated_at' => 'required|date',

            'notes' => 'nullable|array',
            'notes.*.uuid' => 'required|string',
            'notes.*.patient_uuid' => 'required|string',
            'notes.*.client_updated_at' => 'required|date',
        ]);

        $user = $request->user();
        $results = ['created' => [], 'updated' => [], 'conflicts' => []];

        if ($request->has('patients')) {
            foreach ($request->patients as $data) {
                $result = $this->upsertPatient($user, $data);
                $results[$result['action']][] = $result['entity'];
            }
        }

        if ($request->has('visits')) {
            foreach ($request->visits as $data) {
                $result = $this->upsertVisit($user, $data);
                $results[$result['action']][] = $result['entity'];
            }
        }

        if ($request->has('notes')) {
            foreach ($request->notes as $data) {
                $result = $this->upsertNote($data);
                $results[$result['action']][] = $result['entity'];
            }
        }

        return response()->json([
            'results' => $results,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        $patientCount = Patient::where('primary_doctor_id', $user->id)->count();
        $sharedPatientCount = \App\Domains\Patients\Models\PatientShare::where('doctor_id', $user->id)->count();
        $fileCount = PatientFile::whereIn('patient_id', Patient::where('primary_doctor_id', $user->id)->select('id'))->count();

        return response()->json([
            'stats' => [
                'patients' => $patientCount,
                'shared_patients' => $sharedPatientCount,
                'files' => $fileCount,
                'categories' => FileCategory::count(),
            ],
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function patients(Request $request): JsonResponse
    {
        $user = $request->user();

        $patients = Patient::where('primary_doctor_id', $user->id)
            ->with(['visits', 'notes', 'files'])
            ->get();

        return response()->json([
            'data' => $patients,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function patient(Request $request, string $uuid): JsonResponse
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();

        $this->authorize('view', $patient);

        return response()->json([
            'data' => $patient->load(['visits', 'notes', 'files']),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function patientFiles(Request $request, string $uuid): JsonResponse
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();

        $this->authorize('view', $patient);

        $files = $patient->files()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $files,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function patientVisits(Request $request, string $uuid): JsonResponse
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();

        $this->authorize('view', $patient);

        $visits = $patient->visits()->orderBy('visit_date', 'desc')->get();

        return response()->json([
            'data' => $visits,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function patientNotes(Request $request, string $uuid): JsonResponse
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();

        $this->authorize('view', $patient);

        $notes = $patient->notes()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $notes,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function categories(): JsonResponse
    {
        return response()->json([
            'data' => FileCategory::all(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    private function getPatients($user, $lastSync): array
    {
        $query = Patient::where('primary_doctor_id', $user->id)
            ->withTrashed();

        if ($lastSync) {
            $query->where('updated_at', '>', $lastSync);
        }

        return $query->get()->toArray();
    }

    private function getFiles($user, $lastSync): array
    {
        $patientIds = Patient::where('primary_doctor_id', $user->id)->select('id');
        $query = PatientFile::whereIn('patient_id', $patientIds)
            ->withTrashed();

        if ($lastSync) {
            $query->where('updated_at', '>', $lastSync);
        }

        return $query->get()->toArray();
    }

    private function getVisits($user, $lastSync): array
    {
        $patientIds = Patient::where('primary_doctor_id', $user->id)->select('id');
        $query = PatientVisit::whereIn('patient_id', $patientIds)
            ->withTrashed();

        if ($lastSync) {
            $query->where('updated_at', '>', $lastSync);
        }

        return $query->get()->toArray();
    }

    private function getNotes($user, $lastSync): array
    {
        $patientIds = Patient::where('primary_doctor_id', $user->id)->select('id');
        $query = PatientNote::whereIn('patient_id', $patientIds);

        if ($lastSync) {
            $query->where('updated_at', '>', $lastSync);
        }

        return $query->get()->toArray();
    }

    private function upsertPatient($user, array $data): array
    {
        $patient = Patient::withTrashed()->where('uuid', $data['uuid'])->first();

        if ($patient) {
            if (isset($data['_deleted']) && $data['_deleted']) {
                if (!$patient->trashed()) {
                    $patient->delete();
                }
                return ['action' => 'updated', 'entity' => ['uuid' => $data['uuid'], 'status' => 'deleted']];
            }

            $serverUpdatedAt = $patient->client_updated_at ?? $patient->updated_at;
            $clientUpdatedAt = $data['client_updated_at'] ?? now();

            if ($serverUpdatedAt && $clientUpdatedAt && $serverUpdatedAt->gt($clientUpdatedAt)) {
                return ['action' => 'conflicts', 'entity' => [
                    'uuid' => $data['uuid'],
                    'server_version' => $patient->toArray(),
                ]];
            }

            $patient->update([
                'name' => $data['name'] ?? $patient->name,
                'phone' => $data['phone'] ?? $patient->phone,
                'email' => $data['email'] ?? $patient->email,
                'address' => $data['address'] ?? $patient->address,
                'date_of_birth' => $data['date_of_birth'] ?? $patient->date_of_birth,
                'gender' => $data['gender'] ?? $patient->gender,
                'blood_group' => $data['blood_group'] ?? $patient->blood_group,
                'weight' => $data['weight'] ?? $patient->weight,
                'height' => $data['height'] ?? $patient->height,
                'allergies' => $data['allergies'] ?? $patient->allergies,
                'chronic_diseases' => $data['chronic_diseases'] ?? $patient->chronic_diseases,
                'medical_status' => $data['medical_status'] ?? $patient->medical_status,
                'diagnosis' => $data['diagnosis'] ?? $patient->diagnosis,
                'client_updated_at' => $clientUpdatedAt,
            ]);

            return ['action' => 'updated', 'entity' => $patient->toArray()];
        }

        $patient = Patient::create([
            'uuid' => $data['uuid'],
            'primary_doctor_id' => $user->id,
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'gender' => $data['gender'] ?? null,
            'blood_group' => $data['blood_group'] ?? null,
            'weight' => $data['weight'] ?? null,
            'height' => $data['height'] ?? null,
            'allergies' => $data['allergies'] ?? null,
            'chronic_diseases' => $data['chronic_diseases'] ?? null,
            'medical_status' => $data['medical_status'] ?? null,
            'diagnosis' => $data['diagnosis'] ?? null,
            'client_updated_at' => $data['client_updated_at'] ?? now(),
        ]);

        return ['action' => 'created', 'entity' => $patient->toArray()];
    }

    private function upsertVisit($user, array $data): array
    {
        $patient = Patient::where('uuid', $data['patient_uuid'])->first();
        if (!$patient) {
            return ['action' => 'conflicts', 'entity' => ['error' => 'Patient not found', 'patient_uuid' => $data['patient_uuid']]];
        }

        $visit = PatientVisit::withTrashed()->where('uuid', $data['uuid'])->first();

        if ($visit) {
            if (isset($data['_deleted']) && $data['_deleted']) {
                if (!$visit->trashed()) $visit->delete();
                return ['action' => 'updated', 'entity' => ['uuid' => $data['uuid'], 'status' => 'deleted']];
            }

            $visit->update([
                'visit_type' => $data['visit_type'] ?? $visit->visit_type,
                'reason' => $data['reason'] ?? $visit->reason,
                'visit_date' => $data['visit_date'] ?? $visit->visit_date,
                'diagnosis' => $data['diagnosis'] ?? $visit->diagnosis,
                'prescription' => $data['prescription'] ?? $visit->prescription,
                'cost' => $data['cost'] ?? $visit->cost,
                'client_updated_at' => $data['client_updated_at'] ?? now(),
            ]);

            return ['action' => 'updated', 'entity' => $visit->toArray()];
        }

        $visit = PatientVisit::create([
            'uuid' => $data['uuid'],
            'patient_id' => $patient->id,
            'visit_type' => $data['visit_type'] ?? null,
            'reason' => $data['reason'] ?? null,
            'visit_date' => $data['visit_date'] ?? now(),
            'diagnosis' => $data['diagnosis'] ?? null,
            'prescription' => $data['prescription'] ?? null,
            'cost' => $data['cost'] ?? null,
            'client_updated_at' => $data['client_updated_at'] ?? now(),
        ]);

        return ['action' => 'created', 'entity' => $visit->toArray()];
    }

    private function upsertNote(array $data): array
    {
        $patient = Patient::where('uuid', $data['patient_uuid'])->first();
        if (!$patient) {
            return ['action' => 'conflicts', 'entity' => ['error' => 'Patient not found', 'patient_uuid' => $data['patient_uuid']]];
        }

        $note = PatientNote::where('uuid', $data['uuid'])->first();

        if ($note) {
            $note->update([
                'content' => $data['content'] ?? $note->content,
                'client_updated_at' => $data['client_updated_at'] ?? now(),
            ]);

            return ['action' => 'updated', 'entity' => $note->toArray()];
        }

        $note = PatientNote::create([
            'uuid' => $data['uuid'],
            'patient_id' => $patient->id,
            'content' => $data['content'] ?? '',
            'client_updated_at' => $data['client_updated_at'] ?? now(),
        ]);

        return ['action' => 'created', 'entity' => $note->toArray()];
    }
}
