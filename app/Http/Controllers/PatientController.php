<?php

namespace App\Http\Controllers;

use App\Domains\Patients\Models\Patient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Str;

class PatientController extends Controller
{
    public function index()
    {
        return Inertia::render('Patients/Index', [
            'patients' => Patient::latest()->get()
        ]);
    }

    public function shared(Request $request)
    {
        // Because of the DoctorIsolationScope, Patient::query() already includes shared patients.
        // We just need to filter ONLY the shared ones for this specific view.
        // Wait, the scope includes them if they are primary_doctor_id OR shared.
        // To get ONLY shared, we filter where primary_doctor_id != auth()->id().
        
        $patients = Patient::where('primary_doctor_id', '!=', $request->user()->id)
            ->latest()
            ->get();
            
        // We can append the access level manually or load it via relation if needed.
        return Inertia::render('Patients/Shared', [
            'patients' => $patients
        ]);
    }

    public function create()
    {
        return Inertia::render('Patients/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'diagnosis' => 'nullable|string|max:1000',
        ]);

        $validated['code'] = 'PT-' . strtoupper(Str::random(6));
        $validated['primary_doctor_id'] = $request->user()->id;
        $validated['created_by_id'] = $request->user()->id;

        $patient = Patient::create($validated);

        return redirect()->route('patients.show', $patient->uuid)
            ->with('success', 'Patient created successfully.');
    }

    public function edit(Request $request, string $uuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        
        // Ensure only primary doctor or authorized personnel can edit
        abort_if($request->user()->cannot('update', $patient), 403, 'You do not have permission to edit this patient.');

        return Inertia::render('Patients/Edit', [
            'patient' => $patient
        ]);
    }

    public function update(Request $request, string $uuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        
        abort_if($request->user()->cannot('update', $patient), 403, 'You do not have permission to update this patient.');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'diagnosis' => 'nullable|string|max:1000',
        ]);

        $patient->update($validated);

        return redirect()->route('patients.show', $patient->uuid)
            ->with('success', 'Patient updated successfully.');
    }

    public function show(string $uuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        
        // We will fetch files and notes grouped by category, or the frontend can fetch them dynamically.
        // For a true SPA, it's often best to pass them as props if they aren't huge, or use lazy evaluation.
        
        $files = $patient->files()->latest()->get();
        $notes = $patient->notes()->with('author')->latest()->get();

        return Inertia::render('Patients/Show', [
            'patient' => $patient,
            'files' => $files,
            'notes' => $notes,
            'permissions' => [
                'can_edit' => auth()->user()->can('update', $patient),
                'can_share' => auth()->user()->can('share', $patient)
            ]
        ]);
    }
}
