<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientVisitResource;
use App\Models\Patient;
use App\Models\PatientVisit;
use Illuminate\Http\Request;

class PatientVisitController extends Controller
{
    public function index(Request $request, Patient $patient)
    {
        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);

        $visits = $patient->visits()
            ->when($request->filled('from'), fn ($query) => $query->whereDate('visit_date', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('visit_date', '<=', $request->query('to')))
            ->when($request->filled('visit_type'), fn ($query) => $query->where('visit_type', $request->query('visit_type')))
            ->orderByDesc('visit_date')
            ->orderByDesc('visit_time')
            ->paginate($perPage);

        return PatientVisitResource::collection($visits);
    }

    public function store(Request $request, Patient $patient)
    {
        $data = $this->validated($request);
        $data['patient_id'] = $patient->id;

        $visit = PatientVisit::create($data);

        return (new PatientVisitResource($visit))->response()->setStatusCode(201);
    }

    public function show(Patient $patient, PatientVisit $visit)
    {
        abort_unless((int) $visit->patient_id === (int) $patient->id, 404);

        return new PatientVisitResource($visit);
    }

    public function update(Request $request, Patient $patient, PatientVisit $visit)
    {
        abort_unless((int) $visit->patient_id === (int) $patient->id, 404);

        $visit->update($this->validated($request));

        return new PatientVisitResource($visit->refresh());
    }

    public function destroy(Patient $patient, PatientVisit $visit)
    {
        abort_unless((int) $visit->patient_id === (int) $patient->id, 404);
        $visit->delete();

        return response()->json(['message' => 'Deleted']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'uuid' => ['nullable', 'uuid'],
            'visit_type' => ['required', 'string', 'max:100'],
            'visit_type_custom' => ['nullable', 'string', 'max:200'],
            'reason' => ['required', 'string', 'max:200'],
            'reason_custom' => ['nullable', 'string', 'max:200'],
            'visit_date' => ['required', 'date'],
            'visit_time' => ['nullable', 'date_format:H:i'],
            'session_details' => ['nullable', 'array'],
            'session_details.*' => ['string'],
            'diagnosis' => ['nullable', 'string'],
            'prescription' => ['nullable', 'string'],
            'next_visit_date' => ['nullable', 'date'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'client_updated_at' => ['nullable', 'date'],
        ]);
    }
}
