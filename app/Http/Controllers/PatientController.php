<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\PatientFile;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    public function index()
    {
        $patients = Patient::orderBy('id', 'desc')->get();
        $filesCount = PatientFile::count();
        $recentPatients = Patient::where('created_at', '>=', now()->subDays(7))->count();
        
        $monthlyIncome = \App\Models\PatientVisit::whereMonth('visit_date', now()->month)
                            ->whereYear('visit_date', now()->year)
                            ->sum('cost');

        return response()->json([
            'patients' => $patients,
            'stats' => [
                'totalPatients' => $patients->count(),
                'totalFiles' => $filesCount,
                'recentPatients' => $recentPatients,
                'monthlyIncome' => $monthlyIncome,
            ]
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'phone' => 'required|string',
            'address' => 'required|string',
            'diagnosis' => 'nullable|string',
        ]);

        $data = $request->all();
        
        do {
            $code = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (Patient::where('code', $code)->exists());
        
        $data['code'] = $code;

        $patient = Patient::create($data);

        return response()->json($patient, 201);
    }

    public function show($id)
    {
        $patient = Patient::with('files')->findOrFail($id);
        return response()->json($patient);
    }

    public function update(Request $request, $id)
    {
        $patient = Patient::findOrFail($id);
        $request->validate([
            'name' => 'required|string',
            'phone' => 'required|string',
            'address' => 'required|string',
            'diagnosis' => 'nullable|string',
        ]);

        $patient->update($request->all());

        return response()->json($patient);
    }

    public function destroy($id)
    {
        $patient = Patient::findOrFail($id);
        
        $files = PatientFile::where('patient_id', $id)->get();
        foreach ($files as $file) {
            $file->delete();
        }
        
        $patient->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }
}
