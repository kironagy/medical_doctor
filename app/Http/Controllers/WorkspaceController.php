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
     */
    private function getCategories($user)
    {
        $userId = $user ? $user->id : $this->resolveCurrentUserId();

        try {
            return $this->categoryRepo->all($userId);
        } catch (\Throwable $e) {
            Log::warning('[Workspace] CategoryRepository failed, using config defaults: ' . $e->getMessage());
        }

        return config('categories', []);
    }

    public function index()
    {
        $user = auth()->user();
        if (!$user && config('database.default') === 'sqlite') {
            $user = \App\Domains\Users\Models\User::first();
        }

        if (!$user) {
            $user = new \App\Domains\Users\Models\User([
                'id' => 1,
                'name' => 'Doctor',
                'email' => 'doctor@example.com',
                'role' => 'doctor',
            ]);
        }

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

        $result = $this->patientRepo->paginated(10, $page, $status);

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
        $user = $request->user();

        // ═══════════════════════════════════════════════════════════════
        //  CAPTURE BEARER TOKEN FROM FRONTEND
        // ═══════════════════════════════════════════════════════════════
        // The frontend now ALWAYS sends the production API token in the
        // Authorization header, even during offline creation. We capture
        // it here and store it in ApiService so that when WiFi comes back,
        // the sync engine has a valid token to authenticate against the
        // production server.
        //
        // Previously, the offline branch of addPatient() did NOT send the
        // Bearer token, so ApiService::getToken() returned null during
        // sync, causing a 401 from the production server. The patient
        // stayed pending_create forever and never appeared on the web.
        if (config('database.default') === 'sqlite') {
            $bearerToken = $request->bearerToken();
            if ($bearerToken) {
                try {
                    app(\App\Services\Mobile\ApiService::class)->setToken($bearerToken);
                    \Illuminate\Support\Facades\Log::info('[WorkspaceController] Bearer token captured and stored in ApiService');
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[WorkspaceController] Failed to capture Bearer token: ' . $e->getMessage());
                }
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
            try {
                $validated['code'] = (string) random_int(100000, 999999);
            } catch (\Throwable $e) {
                $validated['code'] = (string) mt_rand(100000, 999999);
            }

            $doctorId = $user ? $user->id : $this->resolveCurrentUserId();
            $validated['primary_doctor_id'] = $doctorId;
            $validated['created_by_id'] = $doctorId;

            $patient = $this->patientRepo->create($validated);

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
        if (!$user && config('database.default') !== 'sqlite') {
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
        try {
            $patient = $this->patientRepo->findByUuid($uuid);
        } catch (\RuntimeException $e) {
            Log::warning('[Workspace] patientData - patient not found: ' . $uuid);
            return response()->json(['message' => 'Patient not found'], 404);
        }

        // PERF: Load only the newest 200 files (enough for the UI window + stats)
        // and count the rest cheaply. Previously this loaded EVERY file (with
        // appended url/thumbnail_url) for the patient on every open.
        $totalFiles = $this->fileRepo->countForPatient($uuid);
        $allFiles = $this->fileRepo->forPatient($uuid, 200);
        $files = array_slice($allFiles, 0, 50);

        // Merge offline pending uploads into the file list
        $offlineFiles = $this->offlineFileRepo->findByPatientUuid($uuid);
        if (!empty($offlineFiles)) {
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

            $files = array_merge($offlineMapped, $files);
        }

        $notes = $this->noteRepo->forPatient($uuid);
        $visits = $this->visitRepo->forPatient($uuid);

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
            'total_files' => $totalFiles,
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

        // Permission checks
        $patientModel = Patient::where('uuid', $uuid)->firstOrFail();

        $myShare = null;
        if (($patient['primary_doctor_id'] ?? null) !== $user?->id) {
            $myShare = PatientShare::where('patient_id', $patientModel->id)
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
                'can_edit'        => config('database.default') === 'sqlite' ? true : ($user?->can('update', $patientModel) ?? false),
                'can_delete'      => config('database.default') === 'sqlite' ? true : ($user?->can('delete', $patientModel) ?? false),
                'can_share'       => config('database.default') === 'sqlite' ? true : ($user?->can('share', $patientModel) ?? false),
                'is_primary'      => config('database.default') === 'sqlite' ? true : (($patient['primary_doctor_id'] ?? null) === $user?->id),
                'is_shared'       => config('database.default') === 'sqlite' ? false : ($myShare !== null),
                'access_level'    => $myShare?->access_level ?? 'write',
                'shared_by_name'  => $myShare?->sharedBy?->name ?? null,
            ],
        ];

        return response()->json($payload);
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

        $patient = Patient::where('uuid', $uuid)->first();
        $downloadName = $patient ? (str_replace(' ', '_', $patient->name) . '_' . $patient->code . '_files.zip') : $zipName;

        return response()->download($zipPath, $downloadName)->deleteFileAfterSend(true);
    }

    /**
     * Resolve the current user ID for FK assignment.
     */
    private function resolveCurrentUserId(): int
    {
        $user = auth()->user();
        if ($user) {
            return $user->id;
        }

        $localUser = \App\Domains\Users\Models\User::first();
        if ($localUser) {
            return $localUser->id;
        }

        // Default doctor fallback if local user list is completely empty
        \App\Domains\Users\Models\User::unguard();
        $defaultUser = \App\Domains\Users\Models\User::firstOrCreate(
            ['email' => 'doctor@local.test'],
            [
                'name'     => 'Local Doctor',
                'password' => bcrypt('password'),
                'role'     => 'doctor',
                'uuid'     => (string) \Illuminate\Support\Str::uuid(),
            ]
        );
        \App\Domains\Users\Models\User::reguard();

        return $defaultUser->id;
    }
}
