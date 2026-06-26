<?php

namespace App\Http\Controllers;

use App\Models\PatientVisit;
use Illuminate\Http\Request;

class PatientVisitController extends Controller
{
    // GET /api/patients/{id}/visits
    public function index($patientId)
    {
        $visits = PatientVisit::where('patient_id', $patientId)
            ->orderBy('visit_date', 'desc')
            ->orderBy('visit_time', 'desc')
            ->get()
            ->map(fn($v) => $this->format($v));

        return response()->json($visits);
    }

    // POST /api/patients/{id}/visits
    public function store(Request $request, $patientId)
    {
        $data = $request->validate([
            'visit_type'        => 'required|string|max:100',
            'visit_type_custom' => 'nullable|string|max:200',
            'reason'            => 'required|string|max:200',
            'reason_custom'     => 'nullable|string|max:200',
            'visit_date'        => 'required|date',
            'visit_time'        => 'nullable|date_format:H:i',
            'session_details'   => 'nullable|array',
            'session_details.*' => 'string',
            'diagnosis'         => 'nullable|string',
            'prescription'      => 'nullable|string',
            'next_visit_date'   => 'nullable|date',
            'cost'              => 'nullable|numeric|min:0',
        ]);

        $data['patient_id'] = $patientId;

        $visit = PatientVisit::create($data);

        return response()->json($this->format($visit), 201);
    }

    // GET /api/patients/{id}/visits/{visitId}
    public function show($patientId, $visitId)
    {
        $visit = PatientVisit::where('patient_id', $patientId)->findOrFail($visitId);
        return response()->json($this->format($visit));
    }

    // PUT /api/patients/{id}/visits/{visitId}
    public function update(Request $request, $patientId, $visitId)
    {
        $visit = PatientVisit::where('patient_id', $patientId)->findOrFail($visitId);

        $data = $request->validate([
            'visit_type'        => 'required|string|max:100',
            'visit_type_custom' => 'nullable|string|max:200',
            'reason'            => 'required|string|max:200',
            'reason_custom'     => 'nullable|string|max:200',
            'visit_date'        => 'required|date',
            'visit_time'        => 'nullable|date_format:H:i',
            'session_details'   => 'nullable|array',
            'session_details.*' => 'string',
            'diagnosis'         => 'nullable|string',
            'prescription'      => 'nullable|string',
            'next_visit_date'   => 'nullable|date',
            'cost'              => 'nullable|numeric|min:0',
        ]);

        $visit->update($data);

        return response()->json($this->format($visit));
    }

    // DELETE /api/patients/{id}/visits/{visitId}
    public function destroy($patientId, $visitId)
    {
        $visit = PatientVisit::where('patient_id', $patientId)->findOrFail($visitId);
        $visit->delete();
        return response()->json(['message' => 'Deleted']);
    }

    // ── Helper: normalize output ──────────────────────────────
    private function format(PatientVisit $v): array
    {
        return [
            'id'                => $v->id,
            'patient_id'        => $v->patient_id,
            'visit_type'        => $v->visit_type,
            'visit_type_custom' => $v->visit_type_custom,
            'visit_type_label'  => $v->visit_type_label,
            'reason'            => $v->reason,
            'reason_custom'     => $v->reason_custom,
            'reason_label'      => $v->reason_label,
            'visit_date'        => $v->visit_date?->format('Y-m-d'),
            'visit_time'        => $v->visit_time,
            'session_details'   => $v->session_details ?? [],
            'diagnosis'         => $v->diagnosis,
            'prescription'      => $v->prescription,
            'next_visit_date'   => $v->next_visit_date?->format('Y-m-d'),
            'cost'              => $v->cost,
            'created_at'        => $v->created_at?->format('Y-m-d H:i'),
        ];
    }
}
