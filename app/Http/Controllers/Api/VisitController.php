<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VisitController extends Controller
{
    public function index(Request $request, string $patientUuid)
    {
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        Gate::authorize('view', $patient);

        $visits = $patient->visits()->latest()->get();

        return response()->json($visits);
    }

    public function store(Request $request, string $patientUuid)
    {
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        Gate::authorize('update', $patient);

        $validated = $request->validate([
            'visit_type' => 'required|string|max:255',
            'visit_date' => 'nullable|date',
            'visit_time' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:1000',
            'diagnosis' => 'nullable|string|max:1000',
            'prescription' => 'nullable|string|max:1000',
            'next_visit_date' => 'nullable|date|after_or_equal:today',
            'cost' => 'nullable|numeric|min:0',
        ]);

        $validated['patient_id'] = $patient->id;

        $visit = PatientVisit::create($validated);

        return response()->json($visit, 201);
    }

    public function update(Request $request, string $patientUuid, string $visitId)
    {
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        Gate::authorize('update', $patient);

        $visit = PatientVisit::where('patient_id', $patient->id)
            ->where('uuid', $visitId)
            ->firstOrFail();

        $validated = $request->validate([
            'visit_type' => 'sometimes|string|max:255',
            'visit_date' => 'nullable|date',
            'visit_time' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:1000',
            'diagnosis' => 'nullable|string|max:1000',
            'prescription' => 'nullable|string|max:1000',
            'next_visit_date' => 'nullable|date|after_or_equal:today',
            'cost' => 'nullable|numeric|min:0',
        ]);

        $visit->update($validated);

        return response()->json($visit);
    }

    public function destroy(Request $request, string $patientUuid, string $visitId)
    {
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        Gate::authorize('update', $patient);

        $visit = PatientVisit::where('patient_id', $patient->id)
            ->where('uuid', $visitId)
            ->firstOrFail();

        $visit->delete();

        return response()->json(['message' => 'Visit deleted']);
    }
}
