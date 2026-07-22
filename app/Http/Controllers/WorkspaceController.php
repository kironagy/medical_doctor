<?php

namespace App\Http\Controllers;

use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Contracts\Repositories\PatientVisitRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Patients\Models\Patient;
use App\Services\Mobile\ApiService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WorkspaceController extends Controller
{
    public function __construct(
        private readonly PatientRepositoryInterface $patientRepo,
        private readonly PatientFileRepositoryInterface $fileRepo,
        private readonly PatientNoteRepositoryInterface $noteRepo,
        private readonly PatientVisitRepositoryInterface $visitRepo,
        private readonly UserRepositoryInterface $userRepo,
        private readonly ApiService $apiService,
    ) {}

    private function getCategories($user)
    {
        $defaultCategories = null;
        try {
            $defaultCategories = config('categories');
        } catch (\Throwable $e) {
            $defaultCategories = null;
        }
        if (empty($defaultCategories) || !is_array($defaultCategories)) {
            $configFile = base_path('config/categories.php');
            if (file_exists($configFile)) {
                $defaultCategories = require $configFile;
            } else {
                $defaultCategories = [];
            }
        }

        $preferences = $user->preferences ?? [];
        if (!is_array($preferences)) {
            $preferences = [];
        }
        $customCategories = $preferences['custom_categories'] ?? [];

        $merged = $this->mergeCategories($defaultCategories, $customCategories);

        return empty($merged) ? $defaultCategories : $merged;
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
        Log::info('[LOAD_PATIENTS] === START: Loading patients for DoctorWorkspace ===');

        $user = auth()->user();
        $categories = $this->getCategories($user);

        // API-first: fetch patients from the remote API
        try {
            $patients = $this->patientRepo->all();
            Log::info('[LOAD_PATIENTS] API returned ' . count($patients) . ' patients');
        } catch (\Throwable $e) {
            Log::error('[LOAD_PATIENTS] API call failed: ' . $e->getMessage());
            $patients = [];
        }

        Log::info('[LOAD_PATIENTS] === END: Rendering with ' . count($patients) . ' patients ===');

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

    public function patientList(Request $request)
    {
        $page = $request->input('page', 1);
        $status = $request->input('status');
        $search = $request->input('search');

        try {
            $result = $this->patientRepo->paginated(100, $page, $status, $search);
            Log::info('[WorkspaceController] patientList - API: ' . ($result['meta']['total'] ?? 0) . ' patients');
        } catch (\Throwable $e) {
            Log::error('[WorkspaceController] patientList - API call failed: ' . $e->getMessage());
            $result = [
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 100, 'total' => 0, 'from' => null, 'to' => null],
            ];
        }

        return response()->json($result);
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

        Log::info('[WorkspaceController] Loading patient data from API', ['patient_uuid' => $uuid]);

        try {
            $patient = $this->patientRepo->findByUuid($uuid);
        } catch (\Throwable $e) {
            Log::warning('[WorkspaceController] Patient not found via API: ' . $uuid);
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $t1 = microtime(true);

        try {
            $allFiles = $this->fileRepo->forPatient($uuid);
        } catch (\Throwable $e) {
            Log::warning('[WorkspaceController] Failed to load files: ' . $e->getMessage());
            $allFiles = [];
        }

        $t2 = microtime(true);

        try {
            $notes = $this->noteRepo->forPatient($uuid);
        } catch (\Throwable $e) {
            Log::warning('[WorkspaceController] Failed to load notes: ' . $e->getMessage());
            $notes = [];
        }

        $t3 = microtime(true);

        try {
            $visits = $this->visitRepo->forPatient($uuid);
        } catch (\Throwable $e) {
            Log::warning('[WorkspaceController] Failed to load visits: ' . $e->getMessage());
            $visits = [];
        }

        $t4 = microtime(true);

        $today = now()->toDateString();
        $visitsCollection = collect($visits);

        $latestPastVisit = $visitsCollection
            ->filter(function ($v) use ($today) {
                $vDate = !empty($v['visit_date']) ? substr($v['visit_date'], 0, 10) : substr($v['created_at'] ?? $today, 0, 10);
                return $vDate <= $today;
            })
            ->sortByDesc(function ($v) {
                return !empty($v['visit_date']) ? substr($v['visit_date'], 0, 10) : substr($v['created_at'] ?? '', 0, 10);
            })
            ->first();

        $nextAppointment = $visitsCollection
            ->filter(function ($v) use ($today) {
                return !empty($v['next_visit_date']) && substr($v['next_visit_date'], 0, 10) >= $today;
            })
            ->sortBy('next_visit_date')
            ->first();

        $patientData = $patient;
        $patientData['last_visit_date'] = !empty($latestPastVisit['visit_date'])
            ? substr($latestPastVisit['visit_date'], 0, 10)
            : (isset($latestPastVisit['created_at']) ? substr($latestPastVisit['created_at'], 0, 10) : null);

        $patientData['next_appointment_date'] = !empty($nextAppointment['next_visit_date'])
            ? substr($nextAppointment['next_visit_date'], 0, 10)
            : null;

        $stats = [
            'total_files' => count($allFiles),
            'total_notes' => count($notes),
            'total_visits' => count($visits),
            'recent_uploads' => array_slice($allFiles, 0, 5),
            'upcoming_visit' => $nextAppointment,
            'last_prescription' => collect($allFiles)->first(fn($f) => ($f['category'] ?? '') === 'medications'),
        ];

        $categories = $this->getCategories(auth()->user());
        if (empty($categories)) {
            Log::warning('Categories list is empty for user', ['user_id' => auth()->id()]);
        }

        $user = auth()->user();

        // API handles authorization — if the API returned data, user has access.
        // Set reasonable defaults for permission flags.
        $permissions = [
            'can_edit'        => true,
            'can_delete'      => !($patient['primary_doctor_id'] ?? null) || ($patient['primary_doctor_id'] ?? null) === $user?->id,
            'can_share'       => ($patient['primary_doctor_id'] ?? null) === $user?->id,
            'is_primary'      => ($patient['primary_doctor_id'] ?? null) === $user?->id,
            'is_shared'       => false,
            'access_level'    => 'write',
            'shared_by_name'  => null,
        ];

        $payload = [
            'patient' => $patientData,
            'files'   => $allFiles,
            'notes'   => $notes,
            'visits'  => $visits,
            'shares'  => [],
            'categories' => $categories,
            'stats'   => $stats,
            'permissions' => $permissions,
        ];

        $t6 = microtime(true);

        Log::channel('single')->info('Controller: patientData Profiling', [
            'repo_patient_ms' => round(($t1 - $t0) * 1000, 2),
            'repo_files_ms' => round(($t2 - $t1) * 1000, 2),
            'repo_notes_ms' => round(($t3 - $t2) * 1000, 2),
            'repo_visits_ms' => round(($t4 - $t3) * 1000, 2),
            'controller_processing_ms' => round(($t6 - $t4) * 1000, 2),
            'total_ms' => round(($t6 - $t0) * 1000, 2),
            'files_count' => count($allFiles),
        ]);

        return response()->json($payload);
    }

    public function exportPatient(string $uuid, Request $request)
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
            'exported_by' => auth()->user()?->name ?? 'Unknown',
        ];

        $filename = 'patient_' . $uuid . '_export.json';
        return response()->streamDownload(function () use ($data) {
            echo json_encode($data, JSON_PRETTY_PRINT);
        }, $filename, ['Content-Type' => 'application/json']);
    }

    public function printPatient(string $uuid, Request $request)
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
            'exportedBy' => auth()->user()?->name ?? 'Unknown',
            'doctorName' => auth()->user()?->name ?? 'Doctor',
        ]);
    }

    public function downloadFiles(string $uuid)
    {
        $patientModel = Patient::where('uuid', $uuid)->firstOrFail();
        $jobId = Str::uuid()->toString();

        \App\Jobs\ExportPatientFilesJob::dispatch($patientModel, $jobId);

        return response()->json([
            'jobId' => $jobId,
            'status' => 'processing',
        ]);
    }

    public function checkDownloadStatus(string $jobId)
    {
        $status = \Illuminate\Support\Facades\Cache::get("export_patient_files_{$jobId}");

        if (!$status) {
            return response()->json(['status' => 'not_found'], 404);
        }

        return response()->json($status);
    }

    public function downloadZip(string $uuid, string $jobId)
    {
        $zipName = "patient_{$uuid}_files.zip";
        $zipPath = \Illuminate\Support\Facades\Storage::disk('local')->path($zipName);

        if (!file_exists($zipPath)) {
            abort(404);
        }

        $patient = \App\Domains\Patients\Models\Patient::where('uuid', $uuid)->first();
        $downloadName = $patient ? (str_replace(' ', '_', $patient->name) . '_' . $patient->code . '_files.zip') : $zipName;

        return response()->download($zipPath, $downloadName)->deleteFileAfterSend(true);
    }
}
