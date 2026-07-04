<?php

namespace App\Http\Controllers;

use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Contracts\Repositories\PatientVisitRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientShare;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Str;

class WorkspaceController extends Controller
{
    public function __construct(
        private readonly PatientRepositoryInterface $patientRepo,
        private readonly PatientFileRepositoryInterface $fileRepo,
        private readonly PatientNoteRepositoryInterface $noteRepo,
        private readonly PatientVisitRepositoryInterface $visitRepo,
        private readonly UserRepositoryInterface $userRepo,
    ) {}

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
        $user = auth()->user();
        $categories = $this->getCategories($user);
        $patients = $this->patientRepo->all();

        return Inertia::render('DoctorWorkspace', [
            'patients' => $patients,
            'categories' => $categories,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'specialization' => $user->specialization,
                'role' => $user->role,
            ],
        ]);
    }

    public function patientList()
    {
        $patients = $this->patientRepo->all();
        return response()->json([
            'patients' => $patients,
        ]);
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

        $validated['code'] = 'PT-' . strtoupper(Str::random(6));
        $validated['primary_doctor_id'] = $request->user()->id;
        $validated['created_by_id'] = $request->user()->id;

        $patient = $this->patientRepo->create($validated);

        return response()->json([
            'patient' => $patient,
            'message' => 'Patient created successfully',
        ]);
    }

    public function updatePatient(Request $request, string $uuid)
    {
        $patient = $this->patientRepo->update($uuid, $request->validate([
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
        ]));

        return response()->json([
            'patient' => $patient,
            'message' => 'Patient updated successfully',
        ]);
    }

    public function deletePatient(Request $request, string $uuid)
    {
        $this->patientRepo->delete($uuid);
        return response()->json(['message' => 'Patient archived']);
    }

    public function forceDeletePatient(Request $request, string $uuid)
    {
        $this->patientRepo->forceDelete($uuid);
        return response()->json(['message' => 'Patient permanently deleted']);
    }

    public function restorePatient(Request $request, string $uuid)
    {
        $this->patientRepo->restore($uuid);
        return response()->json(['message' => 'Patient restored']);
    }

    public function patientData(string $uuid)
    {
        $t0 = microtime(true);
        $patient = $this->patientRepo->findByUuid($uuid);
        $t1 = microtime(true);
        $files = $this->fileRepo->forPatient($uuid);
        $t2 = microtime(true);
        $notes = $this->noteRepo->forPatient($uuid);
        $t3 = microtime(true);
        $visits = $this->visitRepo->forPatient($uuid);
        $t4 = microtime(true);

        $nextVisit = collect($visits)->first(fn($v) => ($v['visit_date'] ?? '') >= now()->toDateString());
        $latestVisit = $visits[0] ?? null;

        $stats = [
            'total_files' => count($files),
            'total_notes' => count($notes),
            'total_visits' => count($visits),
            'recent_uploads' => array_slice($files, 0, 5),
            'upcoming_visit' => $nextVisit,
            'last_prescription' => collect($files)->first(fn($f) => ($f['category'] ?? '') === 'medications'),
        ];

        $categories = $this->getCategories(auth()->user());

        $patientData = $patient;
        $patientData['last_visit_date'] = $latestVisit['visit_date'] ?? null;
        $patientData['next_appointment_date'] = $nextVisit['visit_date'] ?? null;

        $user = auth()->user();
        $t5 = microtime(true);

        $payload = [
            'patient' => $patientData,
            'files' => $files,
            'notes' => $notes,
            'visits' => $visits,
            'shares' => [],
            'categories' => $categories,
            'stats' => $stats,
            'permissions' => [
                'can_edit' => $user?->can('update', new Patient()) ?? false,
                'can_share' => $user?->can('share', new Patient()) ?? false,
                'is_primary' => ($patient['primary_doctor_id'] ?? null) === $user?->id,
            ],
        ];

        $t6 = microtime(true);
        $response = response()->json($payload);
        $t7 = microtime(true);

        \Illuminate\Support\Facades\Log::channel('single')->info('Controller: patientData Profiling', [
            'repo_patient_ms' => round(($t1 - $t0) * 1000, 2),
            'repo_files_ms' => round(($t2 - $t1) * 1000, 2),
            'repo_notes_ms' => round(($t3 - $t2) * 1000, 2),
            'repo_visits_ms' => round(($t4 - $t3) * 1000, 2),
            'controller_processing_ms' => round(($t5 - $t4) * 1000, 2),
            'payload_assembly_ms' => round(($t6 - $t5) * 1000, 2),
            'json_encoding_ms' => round(($t7 - $t6) * 1000, 2),
            'total_ms' => round(($t7 - $t0) * 1000, 2)
        ]);

        return $response;
    }

    public function exportPatient(string $uuid)
    {
        $patient = $this->patientRepo->findByUuid($uuid);
        $files = $this->fileRepo->forPatient($uuid);
        $notes = $this->noteRepo->forPatient($uuid);
        $visits = $this->visitRepo->forPatient($uuid);

        $data = [
            'patient' => $patient,
            'files' => $files,
            'notes' => $notes,
            'visits' => $visits,
            'exported_at' => now()->toIso8601String(),
            'exported_by' => auth()->user()->name,
        ];

        $filename = 'patient_' . $uuid . '_export.json';
        return response()->streamDownload(function () use ($data) {
            echo json_encode($data, JSON_PRETTY_PRINT);
        }, $filename, ['Content-Type' => 'application/json']);
    }

    public function printPatient(string $uuid)
    {
        $patient = $this->patientRepo->findByUuid($uuid);
        $files = $this->fileRepo->forPatient($uuid);
        $notes = $this->noteRepo->forPatient($uuid);
        $visits = $this->visitRepo->forPatient($uuid);

        return Inertia::render('PatientPrint', [
            'patient' => $patient,
            'files' => $files,
            'notes' => $notes,
            'visits' => $visits,
            'exportedAt' => now()->toIso8601String(),
            'exportedBy' => auth()->user()->name,
            'doctorName' => auth()->user()->name,
        ]);
    }
}
