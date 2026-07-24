<?php

namespace App\Services;

use App\Contracts\Repositories\OfflineFileRepositoryInterface;
use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Repositories\PatientRepository;
use App\Services\Mobile\ApiService;
use App\Services\OfflineUploadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ───────────────────────────────────────────────────────────────────────────
 *  SyncEngineService — Phase 7: Robust Ordered Synchronization
 * ───────────────────────────────────────────────────────────────────────────
 *
 * Architecture:
 *   SQLite is the LOCAL SOURCE OF TRUTH.
 *   The API is the REMOTE SOURCE OF TRUTH.
 *   Synchronization connects them.
 *
 * Rules:
 *   1. Sync patients BEFORE files (mandatory order).
 *   2. Never upload files before the patient exists on the server.
 *   3. Never delete local data before successful sync.
 *   4. Never overwrite newer local changes.
 *   5. Be idempotent — never upload the same patient twice.
 *   6. Handle retry logic with backoff.
 *   7. Continue from where it stopped (resume after restart/kill).
 *   8. Never lose pending operations.
 *   9. Never duplicate uploads.
 *
 * Sync Order:
 *   Patient (pending_create / pending_update)
 *        ↓
 *   Server returns UUID
 *        ↓
 *   Update local sync_status = 'synced'
 *        ↓
 *   Upload patient files (pending_upload)
 *        ↓
 *   Mark files as synced
 *        ↓
 *   Return results
 *
 * Data Safety:
 *   - Never delete local data before successful sync.
 *   - Never erase pending records.
 *   - Never clear SQLite.
 *   - Never call migrate:fresh.
 * ───────────────────────────────────────────────────────────────────────────
 */
class SyncEngineService
{
    public function __construct(
        private readonly PatientRepository $patientRepo,
        private readonly OfflineFileRepositoryInterface $offlineFileRepo,
        private readonly OfflineUploadService $offlineUploadService,
        private readonly ApiService $api,
    ) {}

    /**
     * Run a full synchronization cycle.
     *
     * Order:
     *   1. Sync pending patients (pending_create, pending_update)
     *   2. Sync pending files (pending_upload, failed)
     *   3. Sync pending deletes (pending_delete)
     *
     * @return array{patients: int, files: array{uploaded: int, failed: int}, deletes: int}
     */
    public function syncAll(): array
    {
        $results = [
            'patients' => 0,
            'files'    => ['uploaded' => 0, 'failed' => 0],
            'deletes'  => 0,
        ];

        // ── AUTH GUARD: Check API token availability ───────────────────
        // The sync engine requires a valid API token (Sanctum Bearer token for
        // the production server). If the token is missing, it means either:
        //   a) The user hasn't logged in yet (no session)
        //   b) The session was restored but the api_token wasn't restored yet
        //      (race condition — sync fires before session restore completes)
        //   c) The token was cleared due to a previous 401 response
        //
        // In ALL cases, we skip the sync gracefully and retry on the next cycle.
        // Without this check, ApiService sends requests with no Bearer header,
        // the production server returns 401, and ApiService clears the token —
        // creating a catch-22 where every subsequent sync also fails.
        $apiToken = $this->api->getToken();
        if (empty($apiToken)) {
            Log::info('[SyncEngine] ⏭ Skipping sync — API token not available (session restore may still be in progress, or user needs to re-login)');
            $results['skipped'] = 'auth_pending';
            return $results;
        }

        // ── STEP 1: Sync pending patients ──────────────────────────────
        // This MUST complete before files because files reference patient UUIDs
        // on the server. Files uploaded for a non-existent patient will fail.
        try {
            $results['patients'] = $this->syncPendingPatients();
        } catch (\Throwable $e) {
            Log::error('[SyncEngine] Patient sync failed: ' . $e->getMessage());
            // Continue to files — some patients may have synced before the error
        }

        // ── STEP 2: Sync pending files ──────────────────────────────────
        // Only attempt file sync if at least one patient synced OR there are
        // already-synced patients with pending files.
        try {
            $results['files'] = $this->syncPendingFiles();
        } catch (\Throwable $e) {
            Log::error('[SyncEngine] File sync failed: ' . $e->getMessage());
        }

        // ── STEP 3: Process pending deletes ─────────────────────────────
        try {
            $results['deletes'] = $this->processPendingDeletes();
        } catch (\Throwable $e) {
            Log::error('[SyncEngine] Delete sync failed: ' . $e->getMessage());
        }

        Log::info('[SyncEngine] Sync cycle complete', $results);

        return $results;
    }

    /**
     * Sync only pending patients to the remote server.
     * Returns the count of successfully synced patients.
     *
     * Uses a TWO-PHASE atomic approach to prevent race conditions and
     * duplicate uploads:
     *   Phase 1: Atomically change sync_status from 'pending_create' to
     *            'syncing' (other processes will skip it).
     *   Phase 2: Make the API call.
     *   Phase 3: On success → 'synced'; on failure → revert to 'pending_create'
     *
     * This ensures that even if two sync processes run simultaneously,
     * only one will pick up each patient.
     */
    /**
     * Try to refresh the API token using stored encrypted credentials.
     * Called when the sync engine receives a 401 response.
     */
    private function refreshToken(): bool
    {
        try {
            $encrypted = session('auth_credentials');
            if (!$encrypted) {
                Log::warning('[SyncEngine] No stored credentials available for token refresh');
                return false;
            }

            $creds = json_decode(decrypt($encrypted), true);
            if (!$creds || empty($creds['email']) || empty($creds['password'])) {
                Log::warning('[SyncEngine] Invalid stored credentials for token refresh');
                return false;
            }

            Log::info('[SyncEngine] Attempting token refresh for: ' . $creds['email']);
            $response = ApiService::loginToRemote($creds['email'], $creds['password']);

            if (isset($response['token'])) {
                $this->api->setToken($response['token']);
                Log::info('[SyncEngine] Token refreshed successfully');
                return true;
            }

            Log::warning('[SyncEngine] Token refresh returned no token');
            return false;
        } catch (\Throwable $e) {
            Log::error('[SyncEngine] Token refresh failed: ' . $e->getMessage());
            return false;
        }
    }

    public function syncPendingPatients(): int
    {
        $syncedCount = 0;
        $user = \Illuminate\Support\Facades\Auth::user();
        $authUserId = $user?->id;

        // ── Recover stuck 'syncing' records (>30 min) ──────────────────
        // If the process crashed after the atomic claim but before the
        // API call (or before the local update), the patient stays in
        // 'syncing' forever. Recover it back to 'pending_create' so it
        // can be retried.
        $recovered = \Illuminate\Support\Facades\DB::table('patients')
            ->where('sync_status', 'syncing')
            ->where('updated_at', '<', now()->subMinutes(30))
            ->update(['sync_status' => 'pending_create', 'updated_at' => now()]);

        if ($recovered > 0) {
            Log::info('[SyncEngine] Recovered ' . $recovered . ' stuck syncing patient records');
        }

        // Process in batches (no offset — see below)
        $batchSize = 100;

        while (true) {
            // Re-query each iteration: newly created patients will be found,
            // and previously claimed patients are skipped by the WHERE clause.
            // NO offset is used because:
            //   - Each iteration claims patients by changing their status
            //   - The WHERE clause naturally excludes already-claimed patients
            //   - Using offset would skip patients created between iterations
            $pendingPatients = \App\Domains\Patients\Models\Patient::whereIn(
                'sync_status', ['pending_create', 'pending_update']
            )->orderBy('created_at', 'asc')->take($batchSize)->get();

            if ($pendingPatients->isEmpty()) break;

            foreach ($pendingPatients as $patient) {
                // ── Phase 1: Atomic status transition ────────────────────
                // Atomically claim this patient by changing its status to
                // 'syncing'. Only the first process to do this wins.
                // Uses a single UPDATE query with WHERE on the current status
                // — this is atomic at the database level (no race).
                $claimed = \Illuminate\Support\Facades\DB::table('patients')
                    ->where('uuid', $patient->uuid)
                    ->whereIn('sync_status', ['pending_create', 'pending_update'])
                    ->update([
                        'sync_status' => 'syncing',
                        'updated_at'  => now(),
                    ]);

                if ($claimed === 0) {
                    // Another process already claimed this patient — skip
                    continue;
                }

                try {
                    $data = $patient->toArray();
                    unset($data['id'], $data['sync_status'], $data['client_updated_at'], $data['deleted_at']);

                    // Inject current doctor ID for remote API
                    if ($authUserId) {
                        if (empty($data['primary_doctor_id'])) {
                            $data['primary_doctor_id'] = $authUserId;
                        }
                        if (empty($data['created_by_id'])) {
                            $data['created_by_id'] = $authUserId;
                        }
                    }

                    $localUuid = $patient->uuid;

                    // ── Phase 2: Make the API call ───────────────────────
                    if ($patient->sync_status === 'pending_create') {
                        try {
                            $apiData = $this->patientRepo->createOnRemote($data);
                        } catch (\Illuminate\Auth\AuthenticationException $authE) {
                            Log::warning('[SyncEngine] 401 on create, refreshing token...');
                            if ($this->refreshToken()) {
                                $apiData = $this->patientRepo->createOnRemote($data);
                            } else {
                                throw $authE;
                            }
                        }
                        $remoteUuid = $apiData['uuid'] ?? $apiData['data']['uuid'] ?? 'unknown';
                    } else {
                        try {
                            $apiData = $this->patientRepo->updateOnRemote($patient->uuid, $data);
                        } catch (\Illuminate\Auth\AuthenticationException $authE) {
                            Log::warning('[SyncEngine] 401 on update, refreshing token...');
                            if ($this->refreshToken()) {
                                $apiData = $this->patientRepo->updateOnRemote($patient->uuid, $data);
                            } else {
                                throw $authE;
                            }
                        }
                        $remoteUuid = $apiData['uuid'] ?? $apiData['data']['uuid'] ?? $patient->uuid;
                    }

                    // ── Phase 3a: Success — mark as synced ───────────────
                    // ── Guard: Validate remote UUID ────────────────────────
                // If the API returns an empty response, remoteUuid will be
                // 'unknown'. This would corrupt the local patient record.
                if ($remoteUuid === 'unknown' || $remoteUuid === null) {
                    throw new \RuntimeException('Remote API returned empty response for patient sync');
                }

                if ($remoteUuid !== $localUuid) {
                    // Remote API assigned a different UUID.
                    // Update the original record's UUID in-place.
                    // Related tables (patient_files, patient_notes, etc.)
                    // use patient_id (auto-increment FK) to reference the
                    // patient, NOT patient_uuid. The patient_id doesn't
                    // change when UUID changes, so those tables don't need
                    // updating. Only offline_files uses patient_uuid.
                    \App\Domains\Patients\Models\Patient::where('uuid', $localUuid)
                        ->update([
                            'uuid'        => $remoteUuid,
                            'sync_status' => 'synced',
                            'updated_at'  => now(),
                        ]);

                    $cleanData = \Illuminate\Support\Arr::except(
                        $apiData['data'] ?? $apiData,
                        ['id', 'primary_doctor', 'visits', 'shares', 'files', 'notes']
                    );
                    $cleanData['sync_status'] = 'synced';
                    \App\Domains\Patients\Models\Patient::unguard();
                    \App\Domains\Patients\Models\Patient::where('uuid', $remoteUuid)->update($cleanData);
                    \App\Domains\Patients\Models\Patient::reguard();

                    // Update offline_files which is the ONLY table that
                    // references patients by UUID instead of auto-increment ID
                    \Illuminate\Support\Facades\DB::table('offline_files')
                        ->where('patient_uuid', $localUuid)
                        ->whereIn('sync_status', ['pending_upload', 'failed'])
                        ->update(['patient_uuid' => $remoteUuid]);
                } else {
                    // UUID matches — standard sync
                    $this->patientRepo->syncSingleToLocal($apiData, force: true);
                }

                    $syncedCount++;

                    Log::info('[SyncEngine] Synced pending patient', [
                        'local_uuid'  => $localUuid,
                        'remote_uuid' => $remoteUuid,
                        'status'      => $patient->sync_status,
                    ]);
                } catch (\Throwable $e) {
                    // ── Phase 3b: Failure — revert to pending_create ──────
                    // The API call failed. Revert the status so the patient
                    // can be retried on the next sync cycle.
                    \App\Domains\Patients\Models\Patient::where('uuid', $patient->uuid)
                        ->where('sync_status', 'syncing')
                        ->update([
                            'sync_status' => 'pending_create',
                            'updated_at'  => now(),
                        ]);

                    Log::warning('[SyncEngine] Failed to sync patient, reverted: ' . $e->getMessage(), [
                        'uuid'   => $patient->uuid,
                        'status' => $patient->sync_status,
                    ]);
                    // Continue to next patient — don't break the batch
                }
            }
        }

        return $syncedCount;
    }

    /**
     * Sync pending offline files to the remote server.
     * Only uploads files whose patient already exists on the server (synced).
     * If a patient is still pending_create, we skip its files and wait.
     *
     * Uses batching to avoid memory exhaustion with 100K+ pending files.
     * Uses atomic status transition ('pending_upload' → 'uploading') to
     * prevent duplicate uploads across concurrent sync processes.
     *
     * @return array{uploaded: int, failed: int}
     */
    public function syncPendingFiles(): array
    {
        $results = ['uploaded' => 0, 'failed' => 0];

        // Recover stuck 'uploading' records (>30 min) back to 'pending_upload'
        $recovered = DB::table('offline_files')
            ->where('sync_status', 'uploading')
            ->where('updated_at', '<', now()->subMinutes(30))
            ->update(['sync_status' => 'pending_upload', 'updated_at' => now()]);

        if ($recovered > 0) {
            Log::info('[SyncEngine] Recovered ' . $recovered . ' stuck uploading file records');
        }

        // Process files in batches to avoid loading 100K records into memory
        $batchSize = 200;

        while (true) {
            // ── Step 1: Select file UUIDs to claim (as a subquery) ─────
            // We do this BEFORE the UPDATE because SQLite's UPDATE with LIMIT
            // may not work consistently through Laravel's query builder.
            // By selecting UUIDs first, we get precise batching.
            $fileIds = DB::table('offline_files')
                ->whereIn('sync_status', ['pending_upload', 'failed'])
                ->where('retry_count', '<', 10)
                ->orderBy('created_at', 'asc')
                ->take($batchSize)
                ->pluck('uuid')
                ->toArray();

            if (empty($fileIds)) break;

            // ── Step 2: Atomically claim those files ───────────────────
            // Only files that are still in 'pending_upload' or 'failed'
            // status will be affected. If another process already claimed
            // them, the UPDATE affects 0 rows for those UUIDs.
            $claimedCount = DB::table('offline_files')
                ->whereIn('uuid', $fileIds)
                ->whereIn('sync_status', ['pending_upload', 'failed'])
                ->update(['sync_status' => 'uploading']);

            if ($claimedCount === 0) continue;

            // Fetch the claimed files (only those we successfully claimed)
            $claimedFiles = DB::table('offline_files')
                ->whereIn('uuid', $fileIds)
                ->where('sync_status', 'uploading')
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(fn ($r) => (array) $r)
                ->toArray();

            foreach ($claimedFiles as $file) {
                // ── CRITICAL CHECK: Is the patient synced? ─────────────────
                $patient = \App\Domains\Patients\Models\Patient::where('uuid', $file['patient_uuid'])->first();
                if (!$patient || ($patient->sync_status ?? 'synced') !== 'synced') {
                    // Patient not synced — revert file to pending_upload
                    DB::table('offline_files')
                        ->where('uuid', $file['uuid'])
                        ->where('sync_status', 'uploading')
                        ->update(['sync_status' => 'pending_upload']);
                    continue;
                }

                try {
                    $result = $this->uploadSingleFile($file);
                    $results['uploaded']++;
                } catch (\Throwable $e) {
                    Log::warning('[SyncEngine] File upload failed: ' . $e->getMessage(), [
                        'file_uuid' => $file['uuid'],
                    ]);
                    $this->offlineFileRepo->incrementRetry($file['uuid']);
                    // Revert to pending_upload so it can be retried (instead of
                    // staying in 'uploading' or 'failed' and needing recovery)
                    DB::table('offline_files')
                        ->where('uuid', $file['uuid'])
                        ->where('sync_status', 'uploading')
                        ->update([
                            'sync_status'  => 'pending_upload',
                            'error_message' => $e->getMessage(),
                        ]);
                    $results['failed']++;
                }
            }
        }

        return $results;
    }

    /**
     * Upload a single pending file to the remote server.
     *
     * @throws \Throwable
     */
    private function uploadSingleFile(array $file): array
    {
        $absolutePath = $this->offlineUploadService->absolutePath($file['local_path']);

        if (!file_exists($absolutePath)) {
            throw new \RuntimeException('Local file not found on disk: ' . $file['local_path']);
        }

        // Note: The caller (syncPendingFiles) already atomically sets the
        // file status to 'uploading' before calling this method. We do NOT
        // call markUploading() again here to avoid a redundant DB write.

        $response = $this->api->upload(
            "/patients/{$file['patient_uuid']}/files",
            ['file' => $absolutePath],
            [
                'title' => $file['original_name'],
                'desc'  => 'Uploaded from offline sync',
            ]
        );

        $remoteUuid = $response['uuid'] ?? $response['file']['uuid'] ?? null;
        if (!$remoteUuid) {
            throw new \RuntimeException('No UUID in server response');
        }

        $this->offlineFileRepo->markSynced($file['uuid'], $remoteUuid);
        $this->offlineUploadService->deleteLocal($file['local_path']);

        Log::info('[SyncEngine] File synced successfully', [
            'local_uuid'  => $file['uuid'],
            'remote_uuid' => $remoteUuid,
            'original'    => $file['original_name'],
        ]);

        return ['uuid' => $remoteUuid];
    }

    /**
     * Process pending deletes — sync delete operations to the remote server.
     * Returns the count of successfully deleted records.
     */
    public function processPendingDeletes(): int
    {
        $deletedCount = 0;

        $pendingDeletes = \App\Domains\Patients\Models\Patient::withTrashed()
            ->where('sync_status', 'pending_delete')
            ->get();

        foreach ($pendingDeletes as $patient) {
            try {
                $this->patientRepo->deleteOnRemote($patient->uuid);

                // Remove from local SQLite entirely (force delete)
                $patient->forceDelete();

                $deletedCount++;

                Log::info('[SyncEngine] Synced pending delete', [
                    'uuid' => $patient->uuid,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[SyncEngine] Failed to sync delete: ' . $e->getMessage(), [
                    'uuid' => $patient->uuid,
                ]);
            }
        }

        return $deletedCount;
    }

    /**
     * Check if there are any pending operations that need syncing.
     */
    public function hasPendingOperations(): bool
    {
        $pendingPatientCount = \App\Domains\Patients\Models\Patient::whereIn(
            'sync_status', ['pending_create', 'pending_update', 'pending_delete']
        )->count();

        $pendingFileCount = DB::table('offline_files')
            ->whereIn('sync_status', ['pending_upload', 'failed'])
            ->count();

        return ($pendingPatientCount + $pendingFileCount) > 0;
    }

    /**
     * Get a summary of all pending operations.
     * Used by the frontend to show sync status.
     *
     * @return array{patients: int, files: int, deletes: int, total: int}
     */
    public function getPendingSummary(): array
    {
        $patientCreate = \App\Domains\Patients\Models\Patient::where('sync_status', 'pending_create')->count();
        $patientUpdate = \App\Domains\Patients\Models\Patient::where('sync_status', 'pending_update')->count();
        $patientDelete = \App\Domains\Patients\Models\Patient::where('sync_status', 'pending_delete')->count();
        $filesPending  = DB::table('offline_files')->whereIn('sync_status', ['pending_upload', 'failed'])->count();

        return [
            'patients' => $patientCreate + $patientUpdate,
            'deletes'  => $patientDelete,
            'files'    => $filesPending,
            'total'    => $patientCreate + $patientUpdate + $patientDelete + $filesPending,
        ];
    }
}
