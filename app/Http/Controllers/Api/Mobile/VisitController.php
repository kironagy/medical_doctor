<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientVisit;
use App\Domains\Mobile\Resources\MobilePatientVisitResource;
use App\Domains\ActivityLogs\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VisitController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger,
    ) {}

    public function index(string $uuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('view', $patient);

        $visits = $patient->visits()->latest()->get();
        return response()->json([
            'data' => $visits->map(fn($v) => new MobilePatientVisitResource($v))->values(),
        ]);
    }

    public function store(Request $request, string $uuid)
    {
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

        $visit = $patient->visits()->create(array_merge($validated, [
            'patient_id' => $patient->id,
        ]));

        $this->logger->log('visit_created', 'PatientVisit', $visit->uuid, [
            'patient_uuid' => $uuid,
        ]);

        return response()->json(new MobilePatientVisitResource($visit), 201);
    }

    public function update(Request $request, string $uuid, string $visitId)
    {
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

        $visit = PatientVisit::where('uuid', $visitId)->where('patient_id', $patient->id)->firstOrFail();
        $visit->update($validated);

        return response()->json(new MobilePatientVisitResource($visit->fresh()));
    }

    public function destroy(string $uuid, string $visitId)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('update', $patient);

        $visit = PatientVisit::where('uuid', $visitId)->where('patient_id', $patient->id)->firstOrFail();
        $visit->delete();

        return response()->json(['message' => 'Visit deleted successfully']);
    }
}
