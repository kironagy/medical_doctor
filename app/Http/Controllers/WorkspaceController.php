<?php

namespace App\Http\Controllers;

use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Contracts\Repositories\OfflineFileRepositoryInterface;
use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Contracts\Repositories\PatientVisitRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientShare;
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
        private readonly OfflineFileRepositoryInterface $offlineFileRepo,
        private readonly CategoryRepositoryInterface $categoryRepo,
    ) {}

    /**
     * Get categories — offline-first via CategoryRepository.
     *
     * The repository handles:
     *   1. API fetch (online) → cache locally → return
     *   2. Local cache (offline) → return
     *   3. Config defaults (last resort) → return
     *
     * This replaces the previous implementation that only read from
     * config + preferences, which failed when offline and the user's
     * preferences weren't synced to the local SQLite.
     */
    private function getCategories($user)
    {
        $userId = $user?->id;

        try {
            return $this->categoryRepo->all($userId);
        } catch (\Throwable $e) {
            Log::warning('[Workspace] CategoryRepository failed, using config defaults: ' . $e->getMessage());
        }

        // Last resort: config defaults
        return config('categories', []);
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

    public function patientList(Request $request)
    {
        $page = $request->input('page', 1);
        $status = $request->input('status');

        // API-first with local fallback (PatientRepository handles offline transparently)
        $result = $this->patientRepo->paginated(10, $page, $status);

        // Normalize API response format (Laravel paginator format -> { data, meta } format)
        if (isset($result['current_page']) && !isset($result['meta'])) {
            $result = [
                'data' => $result['data'] ?? [],
                'meta' => [
                    'current_page' => $result['current_page'] ?? 1,
                    'last_page'    => $result['last_page'] ?? 1,
                    'per_page'     => $result['per_page'] ?? 10,
                    'total'        => $result['total'] ?? 0,
                    'from'         => $result['from'] ?? null,
                    'to'           => $result['to'] ?? null,
                ],
            ];
        }

        Log::info('Sidebar patient list loaded', [
            'url'    => $request->fullUrl(),
            'status' => $status ?: 'active',
            'page'   => $page,
            'count'  => count($result['data'] ?? []),
            'total'  => $result['meta']['total'] ?? 0,
            'fresh'  => true,
        ]);

        return response()->json($result);
    }

    public function storePatient(Request $request)
    {
        $traceFile = '/data/local/tmp/np_traces.txt';
        $traceLine = now()->format('H:i:s.v') . ' [TRACE_P5] WorkspaceController.storePatient() ENTERED' . "\n";
        @file_put_contents($traceFile, $traceLine, FILE_APPEND | LOCK_EX);
        $traceLine = now()->format('H:i:s.v') . ' [TRACE_P5b] URL: ' . $request->fullUrl() . ' Method: ' . $request->method() . "\n";
        @file_put_contents($traceFile, $traceLine, FILE_APPEND | LOCK_EX);
        $traceLine = now()->format('H:i:s.v') . ' [TRACE_P5c] Sessions: ' . json_encode([
            'session_id' => $request->session()->getId(),
            'session_exists' => $request->session()->has('_token'),
            'has_user' => $request->user() ? 'yes' : 'no',
            'user_id' => $request->user()?->id,
        ]) . "\n";
        @file_put_contents($traceFile, $traceLine, FILE_APPEND | LOCK_EX);
        // ── Auth guard: try session first, then Bearer token ────────────────
        // When running offline, the embedded Laravel has no session from the
        // production server. The Sanctum token from localStorage may not be
        // in the local SQLite yet. In these cases, we allow offline creation
        // without a user — the PatientRepository saves with sync_status='pending_create'
        // and assigns doctor IDs when syncing to the production server later.
        $user = $request->user();
        if (!$user) {
            @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' [TRACE_P5d] USER IS NULL - checking Bearer token' . "\n", FILE_APPEND | LOCK_EX);

            // Try to authenticate via Sanctum Bearer token (stored in localStorage)
            $bearerToken = $request->bearerToken();
            if ($bearerToken) {
                try {
                    $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($bearerToken);
                    if ($accessToken && $accessToken->tokenable) {
                        \Illuminate\Support\Facades\Auth::login($accessToken->tokenable);
                        $user = $accessToken->tokenable;
                        @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' [TRACE_P5d2] Authenticated via Bearer token: user_id=' . $user->id . "\n", FILE_APPEND | LOCK_EX);
                    }
                } catch (\Throwable $e) {
                    @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' [TRACE_P5d3] Bearer auth failed: ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
                }
            }

            if (!$user) {
                @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' [TRACE_P5d4] No auth - saving offline without doctor IDs' . "\n", FILE_APPEND | LOCK_EX);
                // Allow offline creation — doctor IDs will be set during sync
            }
        }

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

        try {
            @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' [TRACE_P5e] Validation passed, starting try block' . "\n", FILE_APPEND | LOCK_EX);
            // Generate patient code with fallback — random_int can throw on Android
            try {
                $validated['code'] = (string) random_int(100000, 999999);
            } catch (\Throwable $e) {
                @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' [TRACE_P5f] random_int failed: ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
                $validated['code'] = (string) mt_rand(100000, 999999);
            }
            if ($user) {
                $validated['primary_doctor_id'] = $user->id;
                $validated['created_by_id'] = $user->id;
            } else {
                // Offline: will be assigned when synced to production server
                $validated['primary_doctor_id'] = null;
                $validated['created_by_id'] = null;
            }

            @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' [TRACE_P5g] Calling PatientRepository::create() with name: ' . ($validated['name'] ?? 'none') . "\n", FILE_APPEND | LOCK_EX);
            $patient = $this->patientRepo->create($validated);
            @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' [TRACE_P7] PatientRepository::create() returned. Has uuid: ' . (isset($patient['uuid']) ? 'YES: ' . $patient['uuid'] : 'NO - empty!') . "\n", FILE_APPEND | LOCK_EX);

            return response()->json([
                'patient' => $patient,
                'message' => 'Patient created successfully',
            ]);
        } catch (\Throwable $e) {
            $errorId = substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyz'), 0, 8);
            Log::error('[WorkspaceController] storePatient failed [' . $errorId . ']: ' . $e->getMessage(), [
                'trace' => substr($e->getTraceAsString(), 0, 1000),
                'validated' => $validated,
                'class' => get_class($e),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return response()->json([
                'message' => 'Failed to create patient',
                'error_id' => $errorId,
                'error_detail' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_file' => $e->getFile() . ':' . $e->getLine(),
            ], 422);
        }
    }

    public function updatePatient(Request $request, string $uuid)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated. Please login again.'], 401);
        }

        try {
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
        } catch (\Throwable $e) {
            Log::error('[WorkspaceController] updatePatient failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to update patient'], 500);
        }
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
        try {
            $patient = $this->patientRepo->findByUuid($uuid);
        } catch (\RuntimeException $e) {
            Log::warning('[Workspace] patientData - patient not found: ' . $uuid);
            return response()->json(['message' => 'Patient not found'], 404);
        }
        $t1 = microtime(true);

        // Get all files for stats, but only return first 50 initially to prevent large payload
        $allFiles = $this->fileRepo->forPatient($uuid);
        $files = array_slice($allFiles, 0, 50);

        // ── Phase 7: Merge offline pending uploads into the file list ───────────
        $offlineFiles = $this->offlineFileRepo->findByPatientUuid($uuid);
        if (!empty($offlineFiles)) {
            // Transform offline files to match the frontend's file schema
            $offlineMapped = array_map(function ($of) {
                return [
                    'uuid'          => $of['uuid'],
                    'patient_id'    => $of['patient_uuid'],
                    'title'         => $of['original_name'],
                    'file_name'     => $of['original_name'],
                    'mime_type'     => $of['mime_type'],
                    'extension'     => $of['extension'],
                    'size'          => (int) $of['size'],
                    'type'          => match (true) {
                        str_starts_with($of['mime_type'] ?? '', 'image/') => 'image',
                        str_starts_with($of['mime_type'] ?? '', 'video/') => 'video',
                        str_starts_with($of['mime_type'] ?? '', 'audio/') => 'audio',
                        ($of['mime_type'] ?? '') === 'application/pdf' => 'pdf',
                        default => 'document',
                    },
                    'sync_status'   => $of['sync_status'],
                    'local_path'    => $of['local_path'],
                    'upload_status' => $of['sync_status'],
                    'created_at'    => $of['created_at'],
                    'updated_at'    => $of['updated_at'],
                ];
            }, $offlineFiles);

            // Prepend offline files to the list (newest first)
            $files = array_merge($offlineMapped, $files);
        }

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
            'exported_by' => auth()->user()?->name ?? 'Unknown',
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
            'exportedBy' => auth()->user()?->name ?? 'Unknown',
            'doctorName' => auth()->user()?->name ?? 'Unknown',
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
