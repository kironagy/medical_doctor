<?php

namespace App\Http\Controllers;

use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Contracts\Repositories\PatientRepositoryInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Str;

class PatientController extends Controller
{
    public function __construct(
        private readonly PatientRepositoryInterface $patientRepo,
        private readonly PatientFileRepositoryInterface $fileRepo,
        private readonly PatientNoteRepositoryInterface $noteRepo,
    ) {}

    public function index()
    {
        return Inertia::render('Patients/Index', [
            'patients' => $this->patientRepo->all()
        ]);
    }

    public function shared(Request $request)
    {
        return Inertia::render('Patients/Shared', [
            'patients' => $this->patientRepo->shared($request->user()->id)
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

        $validated['code'] = (string) random_int(100000, 999999);
        $validated['primary_doctor_id'] = $request->user()->id;
        $validated['created_by_id'] = $request->user()->id;

        $patient = $this->patientRepo->create($validated);

        return redirect()->route('patients.show', $patient['uuid'])
            ->with('success', 'Patient created successfully.');
    }

    public function edit(Request $request, string $uuid)
    {
        $patient = $this->patientRepo->findByUuid($uuid);

        return Inertia::render('Patients/Edit', [
            'patient' => $patient
        ]);
    }

    public function update(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'diagnosis' => 'nullable|string|max:1000',
        ]);

        $patient = $this->patientRepo->update($uuid, $validated);

        return redirect()->route('patients.show', $patient['uuid'])
            ->with('success', 'Patient updated successfully.');
    }

    public function storeNote(Request $request)
    {
        $validated = $request->validate([
            'patient_id' => 'required|string',
            'category' => 'required|string',
            'content' => 'required|string',
        ]);

        $note = $this->noteRepo->create($validated['patient_id'], [
            'author_id' => $request->user()->id,
            'category' => $validated['category'],
            'content' => $validated['content'],
        ]);

        return response()->json($note);
    }

    public function show(string $uuid)
    {
        $patient = $this->patientRepo->findByUuid($uuid);
        $files = $this->fileRepo->forPatient($uuid);
        $notes = $this->noteRepo->forPatient($uuid);

        $permissions = [
            'can_edit' => false,
            'can_share' => false,
        ];

        $user = auth()->user();
        if ($user) {
            $pDoctorId = $patient['primary_doctor_id'] ?? null;
            $isOwner = $pDoctorId === $user->id;
            $isSuperAdmin = $user->hasRole('super-admin');
            $permissions['can_edit'] = $isOwner || $isSuperAdmin;
            $permissions['can_share'] = $isOwner || $isSuperAdmin;
        }

        return Inertia::render('Patients/Show', [
            'patient' => $patient,
            'files' => $files,
            'notes' => $notes,
            'permissions' => $permissions,
        ]);
    }
}
