<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Contracts\Repositories\PatientVisitRepositoryInterface;
use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientVisit;
use App\Domains\Mobile\Resources\MobilePatientVisitResource;
use App\Domains\ActivityLogs\Services\ActivityLogger;
use App\Helpers\NativePhp;
use App\Services\NetworkStatusService;
use App\Services\Mobile\ApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class VisitController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly ApiService $api,
        private readonly PatientVisitRepositoryInterface $visitRepo,
        private readonly PatientRepositoryInterface $patientRepo
    ) {}

    public function index(string $uuid)
    {
        if (NativePhp::isRunning() && NetworkStatusService::isOnline()) {
            try {
                $response = $this->api->get("/patients/{$uuid}/visits");
                $visits = $response['data'] ?? $response;
                $this->cacheVisitsLocally($uuid, $visits);
                return response()->json($response);
            } catch (\Throwable $e) {
                Log::warning('[VisitController] API index failed, falling back to local: ' . $e->getMessage());
            }
        }

        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('view', $patient);

        $visits = $patient->visits()->latest()->get();
        return response()->json([
            'data' => $visits->map(fn($v) => new MobilePatientVisitResource($v))->values(),
        ]);
    }

    public function store(Request $request, string $uuid)
    {
        if (NativePhp::isRunning() && NetworkStatusService::isOnline()) {
            try {
                $validated = $request->validate([
                    'visit_type' => 'required|string|max:255',
                    'visit_type_custom' => 'nullable|string|max:255',
                    'reason' => 'nullable|string|max:1000',
                    'reason_custom' => 'nullable|string|max:255',
                    'visit_date' => 'nullable|date',
                    'visit_time' => 'nullable|string|max:255',
                    'session_details' => 'nullable|array',
                    'diagnosis' => 'nullable|string|max:1000',
                    'prescription' => 'nullable|string|max:1000',
                    'next_visit_date' => 'nullable|date|after_or_equal:today',
                    'cost' => 'nullable|numeric|min:0',
                ]);

                $response = $this->api->post("/patients/{$uuid}/visits", $validated);
                return response()->json($response, 201);
            } catch (\Throwable $e) {
                Log::warning('[VisitController] API store failed, falling back to local: ' . $e->getMessage());
            }
        }

        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('update', $patient);

        $validated = $request->validate([
            'visit_type' => 'required|string|max:255',
            'visit_type_custom' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:1000',
            'reason_custom' => 'nullable|string|max:255',
            'visit_date' => 'nullable|date',
            'visit_time' => 'nullable|string|max:255',
            'session_details' => 'nullable|array',
            'diagnosis' => 'nullable|string|max:1000',
            'prescription' => 'nullable|string|max:1000',
            'next_visit_date' => 'nullable|date|after_or_equal:today',
            'cost' => 'nullable|numeric|min:0',
        ]);

        $validated['patient_id'] = $patient->id;
        $result = $this->visitRepo->create($uuid, $validated);

        $this->logger->log('visit_created', 'PatientVisit', $result['uuid'] ?? '', [
            'patient_uuid' => $uuid,
        ]);

        $visit = new PatientVisit();
        $visit->forceFill(\Illuminate\Support\Arr::except($result, ['id']));
        $visit->exists = true;

        return response()->json(new MobilePatientVisitResource($visit), 201);
    }

    public function update(Request $request, string $uuid, string $visitId)
    {
        if (NativePhp::isRunning() && NetworkStatusService::isOnline()) {
            try {
                $validated = $request->validate([
                    'visit_type' => 'sometimes|string|max:255',
                    'visit_type_custom' => 'nullable|string|max:255',
                    'reason' => 'nullable|string|max:1000',
                    'reason_custom' => 'nullable|string|max:255',
                    'visit_date' => 'nullable|date',
                    'visit_time' => 'nullable|string|max:255',
                    'session_details' => 'nullable|array',
                    'diagnosis' => 'nullable|string|max:1000',
                    'prescription' => 'nullable|string|max:1000',
                    'next_visit_date' => 'nullable|date|after_or_equal:today',
                    'cost' => 'nullable|numeric|min:0',
                ]);

                $response = $this->api->put("/patients/{$uuid}/visits/{$visitId}", $validated);
                return response()->json($response);
            } catch (\Throwable $e) {
                Log::warning('[VisitController] API update failed, falling back to local: ' . $e->getMessage());
            }
        }

        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('update', $patient);

        $validated = $request->validate([
            'visit_type' => 'sometimes|string|max:255',
            'visit_type_custom' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:1000',
            'reason_custom' => 'nullable|string|max:255',
            'visit_date' => 'nullable|date',
            'visit_time' => 'nullable|string|max:255',
            'session_details' => 'nullable|array',
            'diagnosis' => 'nullable|string|max:1000',
            'prescription' => 'nullable|string|max:1000',
            'next_visit_date' => 'nullable|date|after_or_equal:today',
            'cost' => 'nullable|numeric|min:0',
        ]);

        $result = $this->visitRepo->update($visitId, $validated);

        $visit = new PatientVisit();
        $visit->forceFill(\Illuminate\Support\Arr::except($result, ['id']));
        $visit->exists = true;

        return response()->json(new MobilePatientVisitResource($visit));
    }

    public function destroy(string $uuid, string $visitId)
    {
        if (NativePhp::isRunning() && NetworkStatusService::isOnline()) {
            try {
                $this->api->delete("/patients/{$uuid}/visits/{$visitId}");
                return response()->json(['message' => 'Visit deleted successfully']);
            } catch (\Throwable $e) {
                Log::warning('[VisitController] API delete failed, falling back to local: ' . $e->getMessage());
            }
        }

        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('update', $patient);

        $this->visitRepo->delete($visitId);
        return response()->json(['message' => 'Visit deleted successfully']);
    }

    private function cacheVisitsLocally(string $patientUuid, array $visits): void
    {
        $patient = Patient::where('uuid', $patientUuid)->first();
        if (!$patient) return;

        foreach ($visits as $visit) {
            if (!isset($visit['uuid'])) continue;

            $cleanData = \Illuminate\Support\Arr::except($visit, ['id', 'patient']);
            $cleanData['patient_id'] = $patient->id;

            try {
                PatientVisit::withoutGlobalScopes()->updateOrCreate(
                    ['uuid' => $visit['uuid']],
                    $cleanData
                );
            } catch (\Throwable $e) {
                Log::warning('[VisitController] Failed to cache visit locally: ' . $e->getMessage());
            }
        }
    }
}
