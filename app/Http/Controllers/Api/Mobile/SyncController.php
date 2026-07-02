<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Domains\Mobile\Services\SyncEngine;
use App\Domains\Mobile\Services\DeltaSyncService;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientVisit;
use App\Domains\Patients\Models\PatientNote;
use App\Domains\Patients\Models\PatientShare;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Media\Models\FileCategory;
use App\Domains\Users\Models\User;
use App\Http\Resources\Mobile\PatientResource;
use App\Http\Resources\Mobile\PatientSyncResource;
use App\Http\Resources\Mobile\PatientFileResource;
use App\Http\Resources\Mobile\PatientVisitResource;
use App\Http\Resources\Mobile\PatientNoteResource;
use App\Http\Resources\Mobile\CategoryResource;
use App\Http\Resources\Mobile\ShareResource;
use App\Http\Resources\Mobile\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(
        protected SyncEngine $syncEngine,
        protected DeltaSyncService $deltaSync,
    ) {}

    public function pull(Request $request): JsonResponse
    {
        $request->validate([
            'last_sync_at' => 'nullable|date',
            'entities' => 'required|array',
            'entities.*' => 'string|in:patients,files,visits,notes,categories,shares,doctors',
        ]);

        $user = $request->user();
        $lastSync = $request->last_sync_at;
        $entities = $request->entities;

        $data = $this->syncEngine->pullChanges($user->id, $lastSync, $entities);

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
            'patients.*.client_updated_at' => 'required|date',

            'visits' => 'nullable|array',
            'visits.*.uuid' => 'required|string',
            'visits.*.patient_uuid' => 'required|string',
            'visits.*.client_updated_at' => 'required|date',

            'notes' => 'nullable|array',
            'notes.*.uuid' => 'required|string',
            'notes.*.patient_uuid' => 'required|string',
            'notes.*.client_updated_at' => 'required|date',

            'shares' => 'nullable|array',
            'shares.*.uuid' => 'required|string',
            'shares.*.patient_uuid' => 'required|string',
            'shares.*.doctor_uuid' => 'required|string',
            'shares.*.client_updated_at' => 'required|date',
        ]);

        $user = $request->user();
        $results = ['created' => [], 'updated' => [], 'conflicts' => [], 'errors' => []];

        if ($request->has('patients')) {
            foreach ($request->patients as $data) {
                $result = $this->deltaSync->upsertPatient($user->id, $data);
                $results[$result['action'] === 'conflict' ? 'conflicts' : ($result['action'] === 'error' ? 'errors' : $result['action'])][] = $result;
            }
        }

        if ($request->has('visits')) {
            foreach ($request->visits as $data) {
                $result = $this->deltaSync->upsertVisit($user->id, $data);
                $results[$result['action'] === 'conflict' ? 'conflicts' : ($result['action'] === 'error' ? 'errors' : $result['action'])][] = $result;
            }
        }

        if ($request->has('notes')) {
            foreach ($request->notes as $data) {
                $result = $this->deltaSync->upsertNote($data);
                $results[$result['action'] === 'conflict' ? 'conflicts' : ($result['action'] === 'error' ? 'errors' : $result['action'])][] = $result;
            }
        }

        if ($request->has('shares')) {
            foreach ($request->shares as $data) {
                $result = $this->deltaSync->upsertShare($data);
                $results[$result['action'] === 'conflict' ? 'conflicts' : ($result['action'] === 'error' ? 'errors' : $result['action'])][] = $result;
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
        $sharedPatientCount = PatientShare::where('doctor_id', $user->id)->count();
        $fileCount = PatientFile::whereIn('patient_id',
            Patient::where('primary_doctor_id', $user->id)->select('id')
        )->count();

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
            ->with(['visits', 'notes', 'files', 'shares'])
            ->get();

        return response()->json([
            'data' => PatientResource::collection($patients),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function patient(Request $request, string $uuid): JsonResponse
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();

        $this->authorize('view', $patient);

        return response()->json([
            'data' => PatientResource::make($patient->load(['visits', 'notes', 'files', 'shares'])),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function patientFiles(Request $request, string $uuid): JsonResponse
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();

        $this->authorize('view', $patient);

        $files = $patient->files()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => PatientFileResource::collection($files),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function patientVisits(Request $request, string $uuid): JsonResponse
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();

        $this->authorize('view', $patient);

        $visits = $patient->visits()->orderBy('visit_date', 'desc')->get();

        return response()->json([
            'data' => PatientVisitResource::collection($visits),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function patientNotes(Request $request, string $uuid): JsonResponse
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();

        $this->authorize('view', $patient);

        $notes = $patient->notes()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => PatientNoteResource::collection($notes),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function categories(): JsonResponse
    {
        return response()->json([
            'data' => CategoryResource::collection(FileCategory::all()),
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
