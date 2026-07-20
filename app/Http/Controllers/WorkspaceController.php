<?php

namespace App\Http\Controllers;

use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Contracts\Repositories\PatientVisitRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientShare;
use App\Repositories\Api\ApiPatientRepository;
use App\Repositories\Eloquent\EloquentPatientRepository;
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
        private readonly ApiPatientRepository $apiPatientRepo,
        private readonly EloquentPatientRepository $eloquentPatientRepo,
        private readonly ApiService $apiService,
    ) {}

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
        $user = auth()->user();
        $categories = $this->getCategories($user);

    // NativePHP (mobile): use the Hybrid repo which handles API + local fallback
    // internally. On web, this resolves to Eloquent directly.
    $patientsSource = 'none';
    $patients = [];
    try {
        $patients = $this->patientRepo->all();
        $patientsSource = (new \ReflectionClass($this->patientRepo))->getShortName() === 'HybridPatientRepository' ? 'hybrid' : 'eloquent';

        // Sync to local SQLite for offline use (safe to call regardless of source)
        if (is_array($patients)) {
            $this->syncPatientsLocally($patients);
        }

        $uuids = collect($patients)->map(fn($p) => ($p['uuid'] ?? '?') . ':' . ($p['name'] ?? '?') . ':' . ($p['code'] ?? '?'))->toArray();
        Log::channel('single')->info('[PATIENT_DEBUG] WorkspaceController::index() - repo returned', [
            'source' => $patientsSource,
            'count' => count($patients),
            'uuids' => $uuids,
        ]);
    } catch (\Illuminate\Auth\AuthenticationException $e) {
        Log::warning('[WorkspaceController] Auth error during index: ' . $e->getMessage());
        $this->apiService->setToken(null);
        $patientsSource = 'auth_error';
    } catch (\Throwable $e) {
        Log::warning('[WorkspaceController] Repo index failed, falling back to local: ' . $e->getMessage());
        $patientsSource = 'repo_fallback';
    }

    // Last-resort fallback: ensure local SQLite is queried directly
    if (empty($patients)) {
        try {
            $patients = $this->eloquentPatientRepo->all();
            $patientsSource = 'local_fallback';
            $uuidsLocal = collect($patients)->map(fn($p) => ($p['uuid'] ?? '?') . ':' . ($p['name'] ?? '?') . ':' . ($p['code'] ?? '?'))->toArray();
            Log::channel('single')->info('[PATIENT_DEBUG] WorkspaceController::index() - got from local', [
                'count' => count($patients),
                'uuids' => $uuidsLocal,
            ]);
        } catch (\Throwable $e) {
            Log::error('[WorkspaceController] Local fallback also failed: ' . $e->getMessage());
            $patients = [];
        }
    }
        Log::channel('single')->info('[PATIENT_DEBUG] WorkspaceController::index() - FINAL', [
            'source' => $patientsSource,
            'count' => count($patients),
            'uuids' => collect($patients)->map(fn($p) => ($p['uuid'] ?? '?') . ':' . ($p['name'] ?? '?') . ':' . ($p['code'] ?? '?'))->toArray(),
        ]);

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
        $result = null;
        $source = 'local';
        $authError = false;

        // ONLINE: Try direct remote API call first
        if (NetworkStatusService::isOnline()) {
            try {
                $token = $this->apiService->getToken();
                if ($token) {
                    $apiResult = $this->apiPatientRepo->paginated(10, $page, $status);
                    if (isset($apiResult['data'])) {
                        $source = 'api';
                        $apiUuids = collect($apiResult['data'])->map(fn($p) => ($p['uuid'] ?? '?') . ':' . ($p['name'] ?? '?') . ':' . ($p['code'] ?? '?'))->toArray();
                        Log::channel('single')->info('[PATIENT_DEBUG] WorkspaceController::patientList() - API returned', [
                            'page' => $page,
                            'status' => $status,
                            'api_total' => $apiResult['meta']['total'] ?? 'N/A',
                            'api_count' => count($apiResult['data']),
                            'api_uuids' => $apiUuids,
                            'raw_api_keys' => array_keys($apiResult),
                        ]);
                        // Sync fetched data to local SQLite for offline use
                        $this->syncPatientsLocally($apiResult['data']);
                        // Verify what's in local SQLite after sync
                        $localAfterSync = Patient::latest()->take(20)->get();
                        $localUuidsAfter = $localAfterSync->map(fn($p) => $p->uuid . ':' . $p->name . ':' . $p->code)->toArray();
                        Log::channel('single')->info('[PATIENT_DEBUG] WorkspaceController::patientList() - local SQLite after sync', [
                            'local_count' => Patient::count(),
                            'recent_local_uuids' => $localUuidsAfter,
                        ]);
                        $result = $apiResult;
                    }
                } else {
                    Log::channel('single')->info('[PATIENT_DEBUG] WorkspaceController::patientList() - no API token');
                }
            } catch (\Illuminate\Auth\AuthenticationException $e) {
                Log::warning('[WorkspaceController] API token expired: ' . $e->getMessage());
                $authError = true;
                // Clear expired token
                $this->apiService->setToken(null);
            } catch (\Throwable $e) {
                Log::warning('[WorkspaceController] API call failed, falling back to local: ' . $e->getMessage());
                NetworkStatusService::setOnline(false);
                $source = 'api_error_fallback';
            }
        } else {
            $source = 'offline_mode';
            Log::channel('single')->info('[PATIENT_DEBUG] WorkspaceController::patientList() - offline mode');
        }

        // OFFLINE / Fallback: Use local EloquentPatientRepository (bypasses Hybrid)
        if ($result === null) {
            if ($authError) {
                // Token expired and no local data — return error so frontend can prompt re-login
                $localResult = $this->eloquentPatientRepo->paginated(10, $page, $status);
                if (($localResult['meta']['total'] ?? 0) > 0) {
                    $result = $localResult;
                    $source = 'local_auth_error';
                    $localUuids = collect($localResult['data'])->map(fn($p) => ($p['uuid'] ?? '?') . ':' . ($p['name'] ?? '?') . ':' . ($p['code'] ?? '?'))->toArray();
                    Log::channel('single')->info('[PATIENT_DEBUG] WorkspaceController::patientList() - local (auth error, data exists)', [
                        'uuids' => $localUuids,
                        'total' => $localResult['meta']['total'] ?? 0,
                    ]);
                } else {
                    Log::channel('single')->warning('[PATIENT_DEBUG] WorkspaceController::patientList() - auth error, no local data');
                    return response()->json([
                        'data' => [],
                        'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 0, 'from' => null, 'to' => null],
                        'auth_error' => true,
                        'message' => 'Session expired. Please login again.',
                    ]);
                }
            } else {
                $result = $this->eloquentPatientRepo->paginated(10, $page, $status);
                $source = 'local_fallback';
            }
        }

        // Normalize API response format (Laravel paginator format -> { data, meta } format)
        if (isset($result['current_page']) && !isset($result['meta'])) {
            $result = [
                'data' => $result['data'] ?? [],
                'meta' => [
                    'current_page' => $result['current_page'] ?? 1,
                    'last_page' => $result['last_page'] ?? 1,
                    'per_page' => $result['per_page'] ?? 10,
                    'total' => $result['total'] ?? 0,
                    'from' => $result['from'] ?? null,
                    'to' => $result['to'] ?? null,
                ],
            ];
        }

        $resultUuids = collect($result['data'] ?? [])->map(fn($p) => ($p['uuid'] ?? '?') . ':' . ($p['name'] ?? '?') . ':' . ($p['code'] ?? '?'))->toArray();
        Log::channel('single')->info('[PATIENT_DEBUG] WorkspaceController::patientList() - FINAL response sent', [
            'url' => $request->fullUrl(),
            'status' => $status ?: 'active',
            'page' => $page,
            'source' => $source,
            'count' => count($result['data'] ?? []),
            'total' => $result['meta']['total'] ?? 0,
            'uuids' => $resultUuids,
        ]);

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

        $validated['code'] = (string) random_int(100000, 999999);
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

        // ONLINE: Try direct remote API call first
        $patient = null;
        $source = 'local';

        if (NetworkStatusService::isOnline()) {
            try {
                $token = $this->apiService->getToken();
                if ($token) {
                    $apiResult = $this->apiPatientRepo->find($uuid);
                    if ($apiResult) {
                        $patient = $apiResult;
                        $source = 'api';
                        // Sync patient record to local SQLite for offline use
                        $this->syncPatientsLocally([$apiResult]);
                    }
                }
            } catch (\Illuminate\Auth\AuthenticationException $e) {
                Log::warning('[WorkspaceController] API token expired for patient data: ' . $e->getMessage());
                $this->apiService->setToken(null);
            } catch (\Throwable $e) {
                Log::warning('[WorkspaceController] API patient data failed, falling back to local: ' . $e->getMessage());
                NetworkStatusService::setOnline(false);
            }
        }

        // Fallback: use local Eloquent repository
        if ($patient === null) {
            try {
                $patient = $this->eloquentPatientRepo->findByUuid($uuid);
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Patient not found'], 404);
            }
        }

        $t1 = microtime(true);

        // Get files: try API response first (includes visits + files), fall back to local repos
        $allFiles = $source === 'api' && isset($patient['files'])
            ? (is_array($patient['files']) ? $patient['files'] : [])
            : $this->fileRepo->forPatient($uuid);
        $files = array_slice($allFiles, 0, 50);

        $t2 = microtime(true);
        $notes = $source === 'api' && isset($patient['notes'])
            ? (is_array($patient['notes']) ? $patient['notes'] : [])
            : $this->noteRepo->forPatient($uuid);
        $t3 = microtime(true);
        $visits = $source === 'api' && isset($patient['visits'])
            ? (is_array($patient['visits']) ? $patient['visits'] : [])
            : $this->visitRepo->forPatient($uuid);
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
        $patientModel = Patient::where('uuid', $uuid)->firstOrFail();

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
