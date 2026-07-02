<?php

namespace App\Http\Controllers;

use App\Domains\Patients\Models\Patient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Str;

class WorkspaceController extends Controller
{
    private function getCategories($user)
    {
        $defaultCategories = config('categories', []);
        $customCategories = $user->preferences['custom_categories'] ?? [];
        return $this->mergeCategories($defaultCategories, $customCategories);
    }

    private function mergeCategories($defaults, $custom)
    {
        $customBySlug = [];
        foreach ($custom as $c) {
            $customBySlug[$c['slug']] = $c;
        }
        $result = [];
        $seen = [];
        foreach ($defaults as $def) {
            $slug = $def['slug'];
            $seen[$slug] = true;
            if (isset($customBySlug[$slug])) {
                $merged = array_merge($def, $customBySlug[$slug]);
                if (isset($merged['is_visible']) && !$merged['is_visible']) continue;
                $result[] = $merged;
            } else {
                $result[] = $def;
            }
        }
        foreach ($custom as $c) {
            $slug = $c['slug'] ?? '';
            if (!$slug || isset($seen[$slug])) continue;
            $seen[$slug] = true;
            if (isset($c['is_visible']) && !$c['is_visible']) continue;
            $result[] = $c;
        }
        usort($result, fn($a, $b) => ($a['order'] ?? 99) - ($b['order'] ?? 99));
        return $result;
    }

    public function index()
    {
        $user = $this->getLocalUser();
        $storedUser = \App\Domains\Mobile\Services\MobileSyncService::getStoredUser();

        $categories = $this->getCategories($user);
        $patients = $this->mapPatientList(Patient::latest()->get());

        return Inertia::render('DoctorWorkspace', [
            'patients' => $patients,
            'categories' => $categories,
            'user' => [
                'id' => $user?->id,
                'name' => $user?->name ?? $storedUser['name'] ?? '',
                'email' => $user?->email ?? $storedUser['email'] ?? '',
                'avatar_url' => $user?->avatar_url ?? $storedUser['avatar_url'] ?? null,
                'specialization' => $user?->specialization ?? $storedUser['specialization'] ?? null,
                'role' => $user?->role ?? $storedUser['role'] ?? 'doctor',
            ],
        ]);
    }

    private function mapPatientList($patients)
    {
        return $patients->map(function ($patient) {
            $latestFile = $patient->files()->latest()->first();
            $latestVisit = $patient->visits()->latest()->first();
            $nextVisit = $patient->visits()->where('visit_date', '>=', now())->first();
            return [
                'id' => $patient->id,
                'uuid' => $patient->uuid,
                'code' => $patient->code,
                'name' => $patient->name,
                'phone' => $patient->phone,
                'email' => $patient->email,
                'address' => $patient->address,
                'diagnosis' => $patient->diagnosis,
                'primary_doctor_id' => $patient->primary_doctor_id,
                'created_at' => $patient->created_at,
                'last_visit' => $latestFile?->created_at,
                'status' => $patient->deleted_at ? 'archived' : 'active',
                'unread' => false,
                'date_of_birth' => $patient->date_of_birth,
                'gender' => $patient->gender,
                'blood_group' => $patient->blood_group,
                'weight' => $patient->weight,
                'height' => $patient->height,
                'allergies' => $patient->allergies,
                'chronic_diseases' => $patient->chronic_diseases,
                'medical_status' => $patient->medical_status,
                'medical_record_number' => $patient->medical_record_number,
                'next_appointment' => $nextVisit?->visit_date,
                'last_visit_date' => $latestVisit?->visit_date,
            ];
        })->values();
    }

    public function patientList()
    {
        $query = Patient::latest();
        if (request()->query('status') === 'archived') {
            $query->onlyTrashed();
        }
        return response()->json([
            'patients' => $this->mapPatientList($query->get()),
        ]);
    }

    private function getLocalUser($request = null)
    {
        return \App\Domains\Mobile\Auth\NativeAuth::user();
    }

    public function storePatient(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'diagnosis' => 'nullable|string|max:1000',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|string|max:50',
            'blood_group' => 'nullable|string|max:10',
            'weight' => 'nullable|numeric',
            'height' => 'nullable|numeric',
            'allergies' => 'nullable|string',
            'chronic_diseases' => 'nullable|string',
            'medical_status' => 'nullable|string|max:100',
            'medical_record_number' => 'nullable|string|max:100',
        ]);

        $user = $this->getLocalUser($request);

        $validated['code'] = 'PT-' . strtoupper(Str::random(6));
        $validated['primary_doctor_id'] = $user?->id;
        $validated['created_by_id'] = $user?->id;

        $patient = Patient::create($validated);

        return response()->json([
            'patient' => $patient,
            'message' => 'Patient created successfully',
        ]);
    }

    public function updatePatient(Request $request, string $uuid)
    {
        $patient = Patient::withTrashed()->where('uuid', $uuid)->firstOrFail();

        $user = $this->getLocalUser($request);
        // We removed web session checks entirely.

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'diagnosis' => 'nullable|string|max:1000',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|string|max:50',
            'blood_group' => 'nullable|string|max:10',
            'weight' => 'nullable|numeric',
            'height' => 'nullable|numeric',
            'allergies' => 'nullable|string',
            'chronic_diseases' => 'nullable|string',
            'medical_status' => 'nullable|string|max:100',
            'medical_record_number' => 'nullable|string|max:100',
        ]);

        $patient->update($validated);

        return response()->json([
            'patient' => $patient,
            'message' => 'Patient updated successfully',
        ]);
    }

    public function deletePatient(Request $request, string $uuid)
    {
        $patient = Patient::withTrashed()->where('uuid', $uuid)->firstOrFail();

        if (!$patient->trashed()) {
            $patient->delete();
        }

        return response()->json(['message' => 'Patient archived']);
    }

    public function forceDeletePatient(Request $request, string $uuid)
    {
        $patient = Patient::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $patient->forceDelete();

        return response()->json(['message' => 'Patient permanently deleted']);
    }

    public function restorePatient(Request $request, string $uuid)
    {
        $patient = Patient::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $patient->restore();

        return response()->json(['message' => 'Patient restored']);
    }

    public function patientData(string $uuid)
    {
        $patient = Patient::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $user = $this->getLocalUser(request());
        $storedUser = \App\Domains\Mobile\Services\MobileSyncService::getStoredUser();

        $files = $patient->files()->latest()->get();
        $notes = $patient->notes()->with('author:id,name,email')->latest()->get();
        $visits = $patient->visits()->latest()->get();
        $shares = $patient->shares()->with('doctor:id,name,email,specialization,code')->latest()->get();
        $nextVisit = $visits->firstWhere('visit_date', '>=', now());
        $latestVisit = $visits->first();

        $stats = [
            'total_files' => $files->count(),
            'total_notes' => $notes->count(),
            'total_visits' => $visits->count(),
            'recent_uploads' => $files->take(5)->values(),
            'upcoming_visit' => $visits->firstWhere('visit_date', '>=', now()),
            'last_prescription' => $files->firstWhere('category', 'medications'),
        ];

        $categories = $this->getCategories($user);

        $patientData = $patient->toArray();
        $patientData['age'] = $patient->age;
        $patientData['last_visit_date'] = $latestVisit?->visit_date;
        $patientData['next_appointment_date'] = $nextVisit?->visit_date;

        return response()->json([
            'patient' => $patientData,
            'files' => $files,
            'notes' => $notes,
            'visits' => $visits,
            'shares' => $shares,
            'categories' => $categories,
            'stats' => $stats,
            'permissions' => [
                'can_edit' => true,
                'can_share' => true,
                'is_primary' => $patient->primary_doctor_id === $user?->id,
            ],
        ]);
    }

    public function exportPatient(string $uuid)
    {
        $patient = Patient::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $user = $this->getLocalUser(request());
        $storedUser = \App\Domains\Mobile\Services\MobileSyncService::getStoredUser();

        $files = $patient->files()->latest()->get();
        $notes = $patient->notes()->with('author:id,name,email')->latest()->get();
        $visits = $patient->visits()->latest()->get();

        $data = [
            'patient' => $patient->toArray(),
            'files' => $files->toArray(),
            'notes' => $notes->toArray(),
            'visits' => $visits->toArray(),
            'exported_at' => now()->toIso8601String(),
            'exported_by' => $user?->name ?? $storedUser['name'] ?? '',
        ];

        $filename = 'patient_' . $patient->uuid . '_export.json';
        return response()->streamDownload(function () use ($data) {
            echo json_encode($data, JSON_PRETTY_PRINT);
        }, $filename, ['Content-Type' => 'application/json']);
    }

    public function printPatient(string $uuid)
    {
        $patient = Patient::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $user = $this->getLocalUser(request());
        $storedUser = \App\Domains\Mobile\Services\MobileSyncService::getStoredUser();

        $files = $patient->files()->latest()->get()->toArray();
        $notes = $patient->notes()->with('author:id,name,email')->latest()->get()->toArray();
        $visits = $patient->visits()->latest()->get()->toArray();

        return Inertia::render('PatientPrint', [
            'patient' => $patient->toArray(),
            'files' => $files,
            'notes' => $notes,
            'visits' => $visits,
            'exportedAt' => now()->toIso8601String(),
            'exportedBy' => $user?->name ?? $storedUser['name'] ?? '',
            'doctorName' => $user?->name ?? $storedUser['name'] ?? '',
        ]);
    }
}
