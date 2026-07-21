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

        // STEP 1: Check internet availability
        $isOnline = NetworkStatusService::isOnline();
        Log::info('[LOAD_PATIENTS] STEP 1: Internet available = ' . ($isOnline ? 'true' : 'false'));

        // STEP 2: Try local SQLite first (instant, works offline)
        $patients = [];
        try {
            $patients = $this->getEloquentPatientRepo()->all();
        } catch (\Throwable $e) {
            Log::error('[LOAD_PATIENTS] ERROR: Local SQLite load failed: ' . $e->getMessage());
            $patients = [];
        }

        $localCount = count($patients);
        Log::info('[LOAD_PATIENTS] STEP 2: SQLite contains ' . $localCount . ' patients');

        // STEP 3: If local has data, use it. If empty AND online, fetch from API.
        if ($localCount > 0) {
            Log::info('[LOAD_PATIENTS] STEP 3: Using local SQLite data (' . $localCount . ' patients)');
        } elseif ($isOnline) {
            Log::info('[LOAD_PATIENTS] STEP 3: Local empty + online = true, calling GET /patients API');
            try {
                $token = $this->apiService->getToken();
                Log::info('[LOAD_PATIENTS] STEP 3a: Token available = ' . ($token ? 'YES (len=' . strlen($token) . ')' : 'NO'));

                if ($token) {
                    Log::info('[LOAD_PATIENTS] STEP 3b: Calling ApiPatientRepository::all()...');
                    $apiPatients = $this->getApiPatientRepo()->all();
                    $apiCount = is_array($apiPatients) ? count($apiPatients) : 0;
                    Log::info('[LOAD_PATIENTS] STEP 3c: API returned ' . $apiCount . ' patients');

                    if ($apiCount > 0) {
                        Log::info('[LOAD_PATIENTS] STEP 3d: Saving ' . $apiCount . ' patients into SQLite...');
                        $this->syncPatientsLocally($apiPatients);

                        // Verify count after save
                        $afterSave = Patient::count();
                        Log::info('[LOAD_PATIENTS] STEP 3e: SQLite now contains ' . $afterSave . ' patients');

                        // Re-read from SQLite to ensure consistent format
                        Log::info('[LOAD_PATIENTS] STEP 3f: Reloading patients from SQLite...');
                        $patients = $this->getEloquentPatientRepo()->all();
                        Log::info('[LOAD_PATIENTS] STEP 3g: Rendering ' . count($patients) . ' patients in UI');
                    } else {
                        Log::warning('[LOAD_PATIENTS] API returned 0 patients (response was empty)');
                    }
                }
            } catch (\Illuminate\Auth\AuthenticationException $e) {
                Log::error('[LOAD_PATIENTS] ERROR: API token invalid/expired: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::error('[LOAD_PATIENTS] ERROR: API bootstrap failed: ' . $e->getMessage());
                Log::error('[LOAD_PATIENTS] ERROR class: ' . get_class($e));
                Log::error('[LOAD_PATIENTS] ERROR file: ' . $e->getFile() . ':' . $e->getLine());
                NetworkStatusService::setOnline(false);
            }
        } else {
            Log::info('[LOAD_PATIENTS] STEP 3: Local empty + offline = true, cannot fetch from API');
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
        $authError = false;

        // OFFLINE-FIRST: Always load from local SQLite first for instant UI.
        $result = $this->getEloquentPatientRepo()->paginated(10, $page, $status);
        Log::channel('single')->info('[PATIENT_DEBUG] WorkspaceController::patientList() - loaded from local SQLite', [
            'page' => $page,
            'local_count' => $result['meta']['total'] ?? 0,
        ]);

        // If local has data, return it immediately (non-blocking).
        // Background sync happens separately via NativeSyncController.
        if (($result['meta']['total'] ?? 0) > 0) {
            return response()->json($result);
        }

        // FORCE-FETCH FROM API if local is empty and we're online.
        // This is critical for first-time users who have no cached data yet.
        if (NetworkStatusService::isOnline()) {
            try {
                $token = $this->apiService->getToken();
                Log::info('[WorkspaceController] patientList bootstrap - token: ' . ($token ? 'YES' : 'NO'));
                if ($token) {
                    $apiResult = $this->getApiPatientRepo()->paginated(10, $page, $status);
                    Log::info('[WorkspaceController] patientList bootstrap - API returned ' . (isset($apiResult['data']) ? count($apiResult['data']) : 0) . ' patients');
                    if (isset($apiResult['data']) && count($apiResult['data']) > 0) {
                        $this->syncPatientsLocally($apiResult['data']);
                        $result = $apiResult;
                    }
                }
            } catch (\Illuminate\Auth\AuthenticationException $e) {
                Log::warning('[WorkspaceController] API token expired: ' . $e->getMessage());
                $authError = true;
                $this->apiService->setToken(null);
            } catch (\Throwable $e) {
                Log::error('[WorkspaceController] API bootstrap failed: ' . $e->getMessage());
                NetworkStatusService::setOnline(false);
            }
        }

        // Auth error with no data
        if ($authError && ($result['meta']['total'] ?? 0) === 0) {
            return response()->json([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 0, 'from' => null, 'to' => null],
                'auth_error' => true,
                'message' => 'Session expired. Please login again.',
            ]);
        }

        return response()->json($result);
    }

    /**
     * Sync remote API patient data into local SQLite for offline availability.
     */
    private function syncPatientsLocally(array $patients): void
    {
        foreach ($patients as $item) {
            if (is_array($item) && isset($item['uuid'])) {
                $cleanData = \Illuminate\Support\Arr::except($item, [
                    'id', 'primary_doctor', 'visits', 'shares', 'files', 'notes'
                ]);
                try {
            Patient::unguard();
            Patient::withoutGlobalScopes()->updateOrCreate(['uuid' => $item['uuid']], $cleanData);
            Patient::reguard();
                } catch (\Exception $e) {
                    Patient::reguard();
                    Log::warning("[WorkspaceController] Failed to sync patient {$item['uuid']}: " . $e->getMessage());
                }
            }
        }
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

        do {
            $validated['code'] = (string) random_int(100000, 999999);
        } while (\App\Domains\Patients\Models\Patient::where('code', $validated['code'])->exists());
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

        // OFFLINE-FIRST: Load EVERYTHING from local SQLite instantly.
        // The local cache is populated by FullSyncService::syncMetadataOnly()
        // which runs before the UI is displayed (via syncAndRefresh).
        try {
            $patient = $this->patientRepo->findByUuid($uuid);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[WorkspaceController] Patient not found: ' . $uuid);
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $t1 = microtime(true);

        // Use fileRepo interface (Hybrid in Native mode) for offline-first loading.
        // HybridRepo tries local SQLite first, then API if online and local empty.
        $allFiles = $this->fileRepo->forPatient($uuid);
        \Illuminate\Support\Facades\Log::info('[WorkspaceController] Loaded ' . count($allFiles) . ' files from ' . (\App\Helpers\NativePhp::isRunning() ? 'Hybrid (offline-first)' : 'Eloquent') . ' repo', ['patient_uuid' => $uuid]);

        $files = array_slice($allFiles, 0, 50);

        $t2 = microtime(true);
        $notes = $this->noteRepo->forPatient($uuid);
        $t3 = microtime(true);
        $visits = $this->visitRepo->forPatient($uuid);
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
            'total_files_count' => count($allFiles),
            'returned_files_count' => count($files),
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
