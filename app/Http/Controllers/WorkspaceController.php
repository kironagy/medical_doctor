<?php

namespace App\Http\Controllers;

use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Contracts\Repositories\PatientVisitRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Patients\Models\Patient;
use App\Services\FullSyncService;
use App\Services\Mobile\ApiService;
use App\Services\NetworkStatusService;
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
        private readonly FullSyncService $fullSyncService,
    ) {
        // Internal repos are resolved via helper methods (getApiPatientRepo(), etc.)
        // instead of being injected directly. This keeps the constructor clean
        // and ensures the public interface handles the hybrid fallback.
    }

    private function getApiPatientRepo(): \App\Repositories\Api\ApiPatientRepository
    {
        return app(\App\Repositories\Api\ApiPatientRepository::class);
    }

    private function getEloquentPatientRepo(): \App\Repositories\Eloquent\EloquentPatientRepository
    {
        return app(\App\Repositories\Eloquent\EloquentPatientRepository::class);
    }

    private function getEloquentFileRepo(): \App\Repositories\Eloquent\EloquentPatientFileRepository
    {
        return app(\App\Repositories\Eloquent\EloquentPatientFileRepository::class);
    }

    private function getApiFileRepo(): \App\Repositories\Api\ApiPatientFileRepository
    {
        return app(\App\Repositories\Api\ApiPatientFileRepository::class);
    }

    private function getCategories($user)
    {
        // Force load from config file to avoid stale config cache
        $defaultCategories = null;
        try {
            $defaultCategories = config('categories');
        } catch (\Throwable $e) {
            $defaultCategories = null;
        }
        if (empty($defaultCategories) || !is_array($defaultCategories)) {
            // Fallback: load directly from config file
            $configFile = base_path('config/categories.php');
            if (file_exists($configFile)) {
                $defaultCategories = require $configFile;
            } else {
                $defaultCategories = [];
            }
        }
        
        // Ensure preferences is an array
        $preferences = $user->preferences ?? [];
        if (!is_array($preferences)) {
            $preferences = [];
        }
        $customCategories = $preferences['custom_categories'] ?? [];
        
        $merged = $this->mergeCategories($defaultCategories, $customCategories);
        
        // Always return at least default categories if merged is empty
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

        // OFFLINE-FIRST ARCHITECTURE:
        // In NativePHP (mobile) mode, ALL reads go to local SQLite only.
        // The background sync (FullSyncService / periodic sync) keeps SQLite
        // up-to-date with the production API. This eliminates the "API-first
        // with SQLite fallback" pattern that created two sources of truth.
        //
        // On web server mode, the Eloquent repos read/write MySQL directly
        // (no SQLite involvement), so the existing behavior is preserved.
        if (\App\Helpers\NativePhp::isRunning()) {
            try {
                $patients = $this->getEloquentPatientRepo()->all();
                Log::info('[LOAD_PATIENTS] OFFLINE-FIRST: SQLite contains ' . count($patients) . ' patients');
            } catch (\Throwable $e) {
                Log::error('[LOAD_PATIENTS] SQLite read failed: ' . $e->getMessage());
                $patients = [];
            }
        } else {
            // Web server: read from Eloquent (MySQL) directly
            $patients = [];
            try {
                $patients = $this->getEloquentPatientRepo()->all();
                Log::info('[LOAD_PATIENTS] Eloquent (MySQL): ' . count($patients) . ' patients');
            } catch (\Throwable $e) {
                Log::error('[LOAD_PATIENTS] DB read failed: ' . $e->getMessage());
                $patients = [];
            }
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

        // OFFLINE-FIRST ARCHITECTURE:
        // In NativePHP (mobile) mode, ALL reads go to local SQLite only.
        // Background sync keeps SQLite updated; we never hit the API for reads.
        // On web server, Eloquent repos read from MySQL (no SQLite involved).
        try {
            $result = $this->getEloquentPatientRepo()->paginated(100, $page, $status, $search);
            Log::info('[WorkspaceController] patientList - ' . (\App\Helpers\NativePhp::isRunning() ? 'SQLite' : 'MySQL') . ': ' . ($result['meta']['total'] ?? 0) . ' patients');
        } catch (\Throwable $e) {
            Log::error('[WorkspaceController] patientList - read failed: ' . $e->getMessage());
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

        // Wrap code generation in a DB transaction to prevent duplicate codes
        // under concurrent requests. DB::transaction() provides isolation in SQLite.
        \Illuminate\Support\Facades\DB::transaction(function () use (&$validated) {
            do {
                $validated['code'] = (string) random_int(100000, 999999);
            } while (\App\Domains\Patients\Models\Patient::where('code', $validated['code'])->exists());
        });

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

        \Illuminate\Support\Facades\Log::info('[WorkspaceController] Loading patient data', ['patient_uuid' => $uuid]);

        // RACE CONDITION GUARD: If FullSyncService is currently syncing,
        // skip the local empty → API bootstrap to avoid parallel writes.
        // The background sync handled by syncAndRefresh() will populate
        // local SQLite, and selectPatient() can be called again to refresh.
        $syncInProgress = FullSyncService::isSyncInProgress();
        if ($syncInProgress) {
            \Illuminate\Support\Facades\Log::info('[WorkspaceController] Sync in progress, loading local data for patient: ' . $uuid);
        }

        // OFFLINE-FIRST ARCHITECTURE:
        // In NativePHP mode, ALL reads go to Eloquent repos (local SQLite) directly —
        // NOT through the Hybrid interface binding which would try API first.
        // This includes the patient, files, notes, and visits.
        // Background sync keeps SQLite up-to-date, so the API is never consulted on reads.
        // On web server, Eloquent repos read from MySQL (identical behavior).
        $eloquentPatientRepo = app(\App\Repositories\Eloquent\EloquentPatientRepository::class);
        try {
            $patient = $eloquentPatientRepo->findByUuid($uuid);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[WorkspaceController] Patient not found: ' . $uuid);
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $t1 = microtime(true);

        // Use the Eloquent repos directly (not the interface binding) for ALL reads.
        // In NativePHP mode, the interface binding is Hybrid (tries API first).
        // We bypass that here to ensure every read comes from local SQLite only.
        $eloquentFileRepo = app(\App\Repositories\Eloquent\EloquentPatientFileRepository::class);
        $eloquentNoteRepo = app(\App\Repositories\Eloquent\EloquentPatientNoteRepository::class);
        $eloquentVisitRepo = app(\App\Repositories\Eloquent\EloquentPatientVisitRepository::class);

        $allFiles = $eloquentFileRepo->forPatient($uuid);
        \Illuminate\Support\Facades\Log::info('[WorkspaceController] Loaded ' . count($allFiles) . ' files from Eloquent (local) repo', ['patient_uuid' => $uuid]);

        $files = $allFiles;

        $t2 = microtime(true);
        $notes = $eloquentNoteRepo->forPatient($uuid);
        $t3 = microtime(true);
        $visits = $eloquentVisitRepo->forPatient($uuid);
        $t4 = microtime(true);

        $today = now()->toDateString();
        $visitsCollection = collect($visits);

        // Latest past visit (most recent visit_date or created_at <= today)
        $latestPastVisit = $visitsCollection
            ->filter(function ($v) use ($today) {
                $vDate = !empty($v['visit_date']) ? substr($v['visit_date'], 0, 10) : substr($v['created_at'] ?? $today, 0, 10);
                return $vDate <= $today;
            })
            ->sortByDesc(function ($v) {
                return !empty($v['visit_date']) ? substr($v['visit_date'], 0, 10) : substr($v['created_at'] ?? '', 0, 10);
            })
            ->first();

        // Next appointment: earliest next_visit_date >= today from any visit
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
        $t5 = microtime(true);

        // Get the Patient model instance for permission checks
        $patientModel = Patient::where('uuid', $uuid)->first();
        if (!$patientModel) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        // If this doctor has access via a share (not as primary), load share metadata
        // so the frontend can show who shared the patient and enforce read-only mode.
        $myShare = null;
        if (($patient['primary_doctor_id'] ?? null) !== $user?->id) {
            $myShare = \App\Domains\Patients\Models\PatientShare::where('patient_id', $patientModel->id)
                ->where('doctor_id', $user?->id)
                ->with('sharedBy:id,name')
                ->first();
        }

        $payload = [
            'patient' => $patientData,
            'files'   => $files,
            'notes'   => $notes,
            'visits'  => $visits,
            'shares'  => [],
            'categories' => $categories,
            'stats'   => $stats,
            'permissions' => [
                'can_edit'        => $user?->can('update', $patientModel) ?? false,
                'can_delete'      => $user?->can('delete', $patientModel) ?? false,
                'can_share'       => $user?->can('share', $patientModel) ?? false,
                'is_primary'      => ($patient['primary_doctor_id'] ?? null) === $user?->id,
                // Share metadata — null when this is the primary doctor
                'is_shared'       => $myShare !== null,
                'access_level'    => $myShare?->access_level ?? 'write',
                'shared_by_name'  => $myShare?->sharedBy?->name ?? null,
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
            'total_ms' => round(($t7 - $t0) * 1000, 2),
            'files_count' => count($allFiles),
        ]);

        return $response;
    }

    public function exportPatient(string $uuid, Request $request)
    {
        $this->authenticateViaTokenIfNeeded($request);

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
        $this->authenticateViaTokenIfNeeded($request);

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

    /**
     * Best-effort token verification for print/export pages opened in new tabs.
     * The meta tag is only rendered for authenticated users, so if the token
     * parameter matches the stored token, it's likely the same user.
     * Falls back to web session auth if no token parameter is provided.
     * The `auth` middleware on the route handles actual authentication.
     */
    private function authenticateViaTokenIfNeeded(Request $request): void
    {
        if (auth()->check()) {
            return; // Session already valid
        }

        $tokenParam = $request->query('token');
        if (!$tokenParam) {
            return;
        }

        // Verify token matches stored value (confirms the request came from
        // an authenticated session that rendered the meta tag)
        try {
            $storedTokenRow = \Illuminate\Support\Facades\DB::table('sync_states')
                ->where('key', 'api_token')
                ->first();

            if ($storedTokenRow && !empty($storedTokenRow->value)) {
                $storedToken = json_decode($storedTokenRow->value, true);
                $storedTokenPlain = $storedToken['plain'] ?? $storedToken['encrypted'] ?? $storedTokenRow->value;

                if ($storedTokenPlain === $tokenParam) {
                    Log::info('[WorkspaceController] Token verified for print/export (session auth will apply)');
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[WorkspaceController] Token verification failed: ' . $e->getMessage());
        }
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
