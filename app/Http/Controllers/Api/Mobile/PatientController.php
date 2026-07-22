<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientVisit;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Mobile\Resources\MobilePatientResource;
use App\Domains\ActivityLogs\Services\ActivityLogger;
use App\Helpers\NativePhp;
use App\Services\NetworkStatusService;
use App\Services\Mobile\ApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class PatientController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly ApiService $api,
        private readonly PatientRepositoryInterface $patientRepo
    ) {}

    public function index(Request $request)
    {
        if (NativePhp::isRunning() && NetworkStatusService::isOnline()) {
            try {
                $params = array_filter([
                    'per_page' => $request->integer('per_page', 20),
                    'page' => $request->integer('page', 1),
                    'search' => $request->get('search'),
                ]);
                $response = $this->api->get('/patients', $params);
                $patients = $response['data'] ?? [];
                $this->cachePatientsLocally($patients);
                return response()->json($response);
            } catch (\Throwable $e) {
                Log::warning('[PatientController] API index failed, falling back to local: ' . $e->getMessage());
            }
        }

        $perPage = min($request->integer('per_page', 20), 100);
        $search = $request->get('search');

        $patients = Patient::with('primaryDoctor:id,name,email')
            ->orderBy('created_at', 'desc')
            ->when($search, fn($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%")
                    ->orWhere('phone', 'like', "%{$s}%")
                    ->orWhere('diagnosis', 'like', "%{$s}%");
            }))
            ->paginate($perPage);

        return MobilePatientResource::collection($patients);
    }

    public function show(string $uuid)
    {
        if (NativePhp::isRunning() && NetworkStatusService::isOnline()) {
            try {
                $response = $this->api->get("/patients/{$uuid}");
                $patientData = $response['data'] ?? $response;
                $this->cachePatientsLocally([$patientData]);

                // Cache relations
                if (isset($patientData['files'])) {
                    foreach ($patientData['files'] as $file) {
                        if (isset($file['uuid'])) {
                            \App\Domains\Media\Models\PatientFile::withoutGlobalScopes()->updateOrCreate(
                                ['uuid' => $file['uuid']],
                                \Illuminate\Support\Arr::except($file, ['id', 'patient', 'creator', 'uploader'])
                            );
                        }
                    }
                }
                if (isset($patientData['visits'])) {
                    foreach ($patientData['visits'] as $visit) {
                        if (isset($visit['uuid'])) {
                            \App\Domains\Patients\Models\PatientVisit::withoutGlobalScopes()->updateOrCreate(
                                ['uuid' => $visit['uuid']],
                                \Illuminate\Support\Arr::except($visit, ['id', 'patient'])
                            );
                        }
                    }
                }

                return response()->json($response);
            } catch (\Throwable $e) {
                Log::warning('[PatientController] API show failed, falling back to local: ' . $e->getMessage());
            }
        }

        $patient = Patient::with([
            'primaryDoctor:id,name,email',
            'visits' => fn($q) => $q->latest(),
            'files' => fn($q) => $q->latest(),
        ])->where('uuid', $uuid)->firstOrFail();

        Gate::authorize('view', $patient);
        return response()->json(new MobilePatientResource($patient));
    }

    public function store(Request $request)
    {
        if (NativePhp::isRunning() && NetworkStatusService::isOnline()) {
            try {
                $validated = $request->validate([
                    'name' => 'required|string|max:255',
                    'phone' => 'nullable|string|max:255',
                    'email' => 'nullable|email|max:255',
                    'address' => 'nullable|string|max:1000',
                    'diagnosis' => 'nullable|string|max:1000',
                    'date_of_birth' => 'nullable|date',
                    'gender' => 'nullable|string|max:255',
                    'blood_group' => 'nullable|string|max:255',
                    'weight' => 'nullable|numeric',
                    'height' => 'nullable|numeric',
                    'allergies' => 'nullable|string',
                    'chronic_diseases' => 'nullable|string',
                    'medical_status' => 'nullable|string|max:255',
                    'medical_record_number' => 'nullable|string|max:100',
                    'code' => 'nullable|string|max:255',
                    'uuid' => 'nullable|uuid',
                ]);

                $response = $this->api->post('/patients', $validated);
                return response()->json($response, 201);
            } catch (\Throwable $e) {
                Log::warning('[PatientController] API store failed, falling back to local: ' . $e->getMessage());
            }
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'diagnosis' => 'nullable|string|max:1000',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|string|max:255',
            'blood_group' => 'nullable|string|max:255',
            'weight' => 'nullable|numeric',
            'height' => 'nullable|numeric',
            'allergies' => 'nullable|string',
            'chronic_diseases' => 'nullable|string',
            'medical_status' => 'nullable|string|max:255',
            'medical_record_number' => 'nullable|string|max:100',
            'code' => 'nullable|string|max:255',
            'uuid' => 'nullable|uuid',
        ]);

        if (empty($validated['code'])) {
            \Illuminate\Support\Facades\DB::transaction(function () use (&$validated) {
                do {
                    $validated['code'] = (string) random_int(100000, 999999);
                } while (Patient::where('code', $validated['code'])->exists());
            });
        } elseif (Patient::where('code', $validated['code'])->exists()) {
            return response()->json(['message' => 'Code already exists', 'errors' => ['code' => ['This code is already in use.']]], 422);
        }

        $validated['primary_doctor_id'] = $request->user()->id;
        $validated['created_by_id'] = $request->user()->id;

        $result = $this->patientRepo->create($validated);

        $this->logger->log('patient_created', 'Patient', $result['uuid'] ?? '', [
            'patient_name' => $result['name'] ?? 'Unknown',
        ]);

        $patient = new Patient();
        $patient->forceFill($result);
        $patient->exists = true;

        return response()->json(new MobilePatientResource($patient), 201);
    }

    public function update(Request $request, string $uuid)
    {
        if (NativePhp::isRunning() && NetworkStatusService::isOnline()) {
            try {
                $validated = $request->validate([
                    'name' => 'sometimes|string|max:255',
                    'phone' => 'nullable|string|max:255',
                    'email' => 'nullable|email|max:255',
                    'address' => 'nullable|string|max:1000',
                    'diagnosis' => 'nullable|string|max:1000',
                    'date_of_birth' => 'nullable|date',
                    'gender' => 'nullable|string|max:255',
                    'blood_group' => 'nullable|string|max:255',
                    'weight' => 'nullable|numeric',
                    'height' => 'nullable|numeric',
                    'allergies' => 'nullable|string',
                    'chronic_diseases' => 'nullable|string',
                    'medical_status' => 'nullable|string|max:255',
                    'medical_record_number' => 'nullable|string|max:100',
                    'code' => 'nullable|string|max:255',
                ]);

                $response = $this->api->put("/patients/{$uuid}", $validated);
                return response()->json($response);
            } catch (\Throwable $e) {
                Log::warning('[PatientController] API update failed, falling back to local: ' . $e->getMessage());
            }
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'diagnosis' => 'nullable|string|max:1000',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|string|max:255',
            'blood_group' => 'nullable|string|max:255',
            'weight' => 'nullable|numeric',
            'height' => 'nullable|numeric',
            'allergies' => 'nullable|string',
            'chronic_diseases' => 'nullable|string',
            'medical_status' => 'nullable|string|max:255',
            'medical_record_number' => 'nullable|string|max:100',
            'code' => 'nullable|string|max:255',
        ]);

        $updated = $this->patientRepo->update($uuid, $validated);

        $this->logger->log('patient_updated', 'Patient', $uuid, [
            'patient_name' => $validated['name'] ?? 'Unknown',
        ]);

        $patient = new Patient();
        $patient->forceFill($updated);
        $patient->exists = true;

        return response()->json(new MobilePatientResource($patient));
    }

    public function destroy(string $uuid)
    {
        if (NativePhp::isRunning() && NetworkStatusService::isOnline()) {
            try {
                $this->api->delete("/patients/{$uuid}");
                return response()->json(['message' => 'Patient deleted successfully']);
            } catch (\Throwable $e) {
                Log::warning('[PatientController] API delete failed, falling back to local: ' . $e->getMessage());
            }
        }

        $patientData = $this->patientRepo->findByUuid($uuid);
        $patient = new Patient();
        $patient->forceFill($patientData);
        $patient->exists = true;

        Gate::authorize('delete', $patient);
        $this->patientRepo->delete($uuid);

        $this->logger->log('patient_deleted', 'Patient', $uuid, [
            'patient_name' => $patientData['name'] ?? 'Unknown',
        ]);

        return response()->json(['message' => 'Patient deleted successfully']);
    }

    private function cachePatientsLocally(array $patients): void
    {
        foreach ($patients as $item) {
            if (!isset($item['uuid'])) continue;

            $cleanData = \Illuminate\Support\Arr::except($item, [
                'id', 'primary_doctor', 'visits', 'shares', 'files', 'notes'
            ]);

            try {
                Patient::withoutGlobalScopes()->updateOrCreate(
                    ['uuid' => $item['uuid']],
                    $cleanData
                );
            } catch (\Throwable $e) {
                Log::warning('[PatientController] Failed to cache patient locally: ' . $e->getMessage());
            }
        }
    }
}
