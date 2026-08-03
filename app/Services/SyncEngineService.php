<?php

namespace App\Services;

use App\Contracts\Repositories\OfflineFileRepositoryInterface;
use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Repositories\PatientRepository;
use App\Services\Mobile\ApiService;
use App\Services\OfflineUploadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
     *   3. Sync pending notes (pending_create, pending_delete)
     *   4. Sync pending deletes (pending_delete)
     *
     * @return array{patients: int, files: array{uploaded: int, failed: int}, notes: array{uploaded: int, deleted: int, failed: int}, deletes: int}
     */
    public function syncAll(): array
    {
        $results = [
            'patients' => 0,
            'files'    => ['uploaded' => 0, 'failed' => 0],
            'notes'    => ['uploaded' => 0, 'deleted' => 0, 'failed' => 0],
            'deletes'  => 0,
            'file_deletes' => 0,
            'file_updates' => 0,
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

        // ── STEP 2b: Sync locally-stored patient_files to production ───
        // Files uploaded via chunk upload are stored in SQLite patient_files
        // with remote_uuid = null. This step pushes them to the production server.
        try {
            $results['local_files'] = $this->syncLocalPatientFiles();
        } catch (\Throwable $e) {
            Log::error('[SyncEngine] Local patient_files sync failed: ' . $e->getMessage());
        }

        // ── STEP 3: Sync pending notes ───────────────────────────────────
        // Must run AFTER patient sync (notes reference patients on the server).
        try {
            $results['notes'] = $this->syncPendingNotes();
        } catch (\Throwable $e) {
            Log::error('[SyncEngine] Note sync failed: ' . $e->getMessage());
        }

        // ── STEP 3a: Sync pending visits ─────────────────────────────────
        // Must run AFTER patient sync (visits reference patients on the server).
        try {
            $results['visits'] = $this->syncPendingVisits();
        } catch (\Throwable $e) {
            Log::error('[SyncEngine] Visit sync failed: ' . $e->getMessage());
        }

        // ── STEP 4: Process pending deletes ─────────────────────────────
        try {
            $results['deletes'] = $this->processPendingDeletes();
        } catch (\Throwable $e) {
            Log::error('[SyncEngine] Delete sync failed: ' . $e->getMessage());
        }

        // ── STEP 5: Process pending file deletes ───────────────────────
        try {
            $results['file_deletes'] = $this->processPendingFileDeletes();

        } catch (\Throwable $e) {
            Log::error('[SyncEngine] File delete sync failed: ' . $e->getMessage());
        }

        // ── STEP 6: Process pending file updates ───────────────────────
        try {
            $results['file_updates'] = $this->processPendingFileUpdates();
        } catch (\Throwable $e) {
            Log::error('[SyncEngine] File update sync failed: ' . $e->getMessage());
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
                    // ── Capture the ORIGINAL status BEFORE it was changed to 'syncing'. ──
                    // $patient still holds the pre-update in-memory value (Eloquent model
                    // was loaded before the atomic DB UPDATE above), so this is reliable.
                    // We save it here to use in the failure-revert path (Phase 3b).
                    $originalStatus = $patient->sync_status; // 'pending_create' or 'pending_update'

                    // ── Phase 2: Make the API call ───────────────────────
                    if ($originalStatus === 'pending_create') {
                        try {
                            $apiData = $this->api->post('/patients', $data);
                        } catch (\Illuminate\Auth\AuthenticationException $authE) {
                            Log::warning('[SyncEngine] 401 on create, refreshing token...');
                            if ($this->refreshToken()) {
                                $apiData = $this->api->post('/patients', $data);
                            } else {
                                throw $authE;
                            }
                        }
                        $remoteUuid = $apiData['uuid'] ?? $apiData['data']['uuid'] ?? 'unknown';
                    } else {
                        try {
                            $apiData = $this->api->put("/patients/{$patient->uuid}", $data);
                        } catch (\Illuminate\Auth\AuthenticationException $authE) {
                            Log::warning('[SyncEngine] 401 on update, refreshing token...');
                            if ($this->refreshToken()) {
                                $apiData = $this->api->put("/patients/{$patient->uuid}", $data);
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

                    $validColumns = \Illuminate\Support\Facades\Schema::getColumnListing('patients');
                    $cleanData = array_intersect_key($cleanData, array_flip($validColumns));

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
                    // ═══ CRITICAL FIX: Mark as synced IMMEDIATELY after API call ═══
                    // Previously, syncSingleToLocal was the ONLY path to 'synced'.
                    // If it failed (e.g. DoctorIsolationScope, column mismatch),
                    // the catch block reverted to 'pending_create', causing an
                    // infinite loop of duplicate POST requests.
                    \Illuminate\Support\Facades\DB::table('patients')
                        ->where('uuid', $localUuid)
                        ->where('sync_status', 'syncing')
                        ->update([
                            'sync_status' => 'synced',
                            'updated_at'  => now(),
                        ]);
                }

                    $syncedCount++;

                    Log::info('[SyncEngine] Synced pending patient', [
                        'local_uuid'  => $localUuid,
                        'remote_uuid' => $remoteUuid,
                        'status'      => 'synced',
                    ]);
                } catch (\Throwable $e) {
                    // ── Phase 3b: Failure — revert to ORIGINAL status ─────
                    // The API call failed. Revert the status back to what it
                    // was BEFORE this sync attempt so it can be retried on the
                    // next sync cycle.
                    //
                    // BUG FIX: Previously ALWAYS reverted to 'pending_create',
                    // which corrupted 'pending_update' patients — they would be
                    // re-created on the next sync instead of updated.
                    $revertStatus = $originalStatus ?? 'pending_create';
                    \App\Domains\Patients\Models\Patient::where('uuid', $patient->uuid)
                        ->where('sync_status', 'syncing')
                        ->update([
                            'sync_status' => $revertStatus,
                            'updated_at'  => now(),
                        ]);

                    Log::warning('[SyncEngine] Failed to sync patient, reverted to ' . $revertStatus . ': ' . $e->getMessage(), [
                        'uuid'            => $patient->uuid,
                        'original_status' => $revertStatus,
                    ]);
                    // Continue to next patient — don't break the batch
                }
            }
        }

        return $syncedCount;
    }

    /**
     * Sync locally-stored patient_files to the production server.
     *
     * When a file is uploaded via the chunk system (useUploads.js), it is
     * stored locally in SQLite (patient_files table) with:
     *   - upload_status = 'ready'
     *   - remote_uuid = null  (no production record yet)
     *
     * This method finds those files and pushes them to the production API.
     * On success, remote_uuid is set to the server-assigned UUID.
     *
     * Files without remote_uuid and with a valid local file_path are candidates.
     * We skip files whose patient is not yet synced (patient still pending_create).
     *
     * @return array{uploaded: int, failed: int}
     */
    public function syncLocalPatientFiles(): array
    {
        $results = ['uploaded' => 0, 'failed' => 0];

        $pendingFiles = \App\Domains\Media\Models\PatientFile::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )
            ->whereNull('remote_uuid')
            ->where('upload_status', 'ready')
            ->where('sync_status', '!=', 'pending_delete')
            ->orderBy('created_at', 'asc')
            ->limit(20) // Process in small batches to avoid memory issues
            ->get();

        Log::info('[SyncEngine] syncLocalPatientFiles — found ' . $pendingFiles->count() . ' pending files to sync');

        foreach ($pendingFiles as $file) {
            // ── BUG FIX: Re-query patient directly from DB to avoid stale Eloquent
            // relationship cache. When syncPendingPatients() runs in the same cycle,
            // the patient's sync_status changes from 'pending_create' → 'synced' in
            // the DB but $file->patient uses the cached Eloquent model which still
            // holds the old status. A fresh DB::table query bypasses that cache.
            $patientRecord = \Illuminate\Support\Facades\DB::table('patients')
                ->where('id', $file->patient_id)
                ->first();

            // Skip if patient not synced to production yet
            if (!$patientRecord || ($patientRecord->sync_status ?? 'synced') !== 'synced') {
                Log::debug('[SyncEngine] Skipping file — patient not synced yet', [
                    'file_uuid'      => $file->uuid,
                    'patient_id'     => $file->patient_id,
                    'patient_status' => $patientRecord ? ($patientRecord->sync_status ?? 'null') : 'NOT_FOUND',
                ]);
                continue;
            }

            // Use the patient UUID from the fresh DB record (may differ after sync)
            $patientUuid = $patientRecord->uuid;

            $absolutePath = Storage::disk('local')->path($file->file_path);
            if (!file_exists($absolutePath)) {
                Log::warning('[SyncEngine] Local patient_file not found on disk', [
                    'uuid'      => $file->uuid,
                    'file_path' => $file->file_path,
                ]);
                // Mark as synced with self-UUID to prevent infinite retry on missing files
                \App\Domains\Media\Models\PatientFile::where('uuid', $file->uuid)
                    ->update(['remote_uuid' => $file->uuid, 'sync_status' => 'synced']);
                continue;
            }

            try {
                $response = $this->api->upload(
                    "/patients/{$patientUuid}/files",
                    ['file' => $absolutePath],
                    [
                        'title'    => $file->title ?? $file->file_name,
                        'desc'     => $file->desc ?? '',
                        'category' => $file->category ?? null,
                        'date'     => ($file->date ? \Carbon\Carbon::parse($file->date)->format('Y-m-d') : now()->format('Y-m-d')),
                    ]
                );

                $remoteUuid = $response['uuid'] ?? $response['file']['uuid'] ?? null;
                if (!$remoteUuid) {
                    throw new \RuntimeException('No UUID returned from production server');
                }

                // Store remote UUID so we skip this file in future cycles
                \App\Domains\Media\Models\PatientFile::where('uuid', $file->uuid)
                    ->update([
                        'remote_uuid' => $remoteUuid,
                        'sync_status' => 'synced',
                        'updated_at'  => now(),
                    ]);

                $results['uploaded']++;
                Log::info('[SyncEngine] Local file synced to production', [
                    'local_uuid'  => $file->uuid,
                    'remote_uuid' => $remoteUuid,
                    'patient'     => $patientUuid,
                    'file_name'   => $file->file_name,
                ]);
            } catch (\Throwable $e) {
                $results['failed']++;
                Log::warning('[SyncEngine] Local patient_file sync failed', [
                    'uuid'        => $file->uuid,
                    'error'       => $e->getMessage(),
                    'file_name'   => $file->file_name,
                    'patient_uuid' => $patientUuid,
                ]);
            }
        }

        return $results;
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
                $patient = \Illuminate\Support\Facades\DB::table('patients')->where('uuid', $file['patient_uuid'])->first();
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
                        'file_uuid'   => $file['uuid'],
                        'retry_count' => $file['retry_count'] ?? 0,
                    ]);
                    $this->offlineFileRepo->incrementRetry($file['uuid']);

                    // ── BUG-013 FIX: Use 'failed' status after failure ──────────
                    // Old code reverted to 'pending_upload' even after failure.
                    // This made it impossible to distinguish "waiting to upload"
                    // from "tried and failed". Now we use 'failed' so the UI can
                    // show the user the file is stuck. The sync query picks up
                    // both 'pending_upload' AND 'failed' (retry_count < 10),
                    // so retries still happen automatically on next sync cycle.
                    $newRetryCount = ($file['retry_count'] ?? 0) + 1;
                    $finalStatus = $newRetryCount >= 10 ? 'failed' : 'pending_upload';
                    DB::table('offline_files')
                        ->where('uuid', $file['uuid'])
                        ->where('sync_status', 'uploading')
                        ->update([
                            'sync_status'   => $finalStatus,
                            'error_message' => $e->getMessage(),
                            'updated_at'    => now(),
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

        $extraParams = [
            'title' => $file['title'] ?? $file['original_name'],
            'desc'  => $file['desc'] ?? '',
        ];
        if (!empty($file['category'])) {
            $extraParams['category'] = $file['category'];
        }
        if (!empty($file['date'])) {
            $extraParams['date'] = $file['date'];
        }

        $response = $this->api->upload(
            "/patients/{$file['patient_uuid']}/files",
            ['file' => $absolutePath],
            $extraParams
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

        // ═══ SYNC-002 FIX: Query both soft-deleted AND non-soft-deleted ═══
        // On SQLite, PatientController::destroy() marks patients as
        // pending_delete WITHOUT soft-deleting them (because the sync engine
        // needs to find them to process the delete). On MySQL, patients are
        // soft-deleted normally. We query BOTH to handle both cases:
        //   1. Soft-deleted patients (MySQL path) — use withTrashed()
        //   2. Non-soft-deleted patients with pending_delete (SQLite path)
        $pendingDeletes = \App\Domains\Patients\Models\Patient::withTrashed()
            ->where('sync_status', 'pending_delete')
            ->get()
            ->merge(
                \App\Domains\Patients\Models\Patient::where('sync_status', 'pending_delete')
                    ->whereNull('deleted_at')
                    ->get()
            )
            ->unique('uuid');

        foreach ($pendingDeletes as $patient) {
            try {
                try {
                    $this->api->delete("/patients/{$patient->uuid}");
                } catch (\Throwable $e) {
                    // 404 (Not Found) means the patient was already deleted on the remote server — treat as success
                    if (!($e->getCode() === 404 || str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'Not Found'))) {
                        throw $e;
                    }
                }

                // Remove from local SQLite entirely
                // On SQLite (non-trashed): use delete() since forceDelete()
                // is designed for trashed models and may behave unexpectedly
                // on non-trashed records.
                // On MySQL (trashed): use forceDelete() as before.
                if ($patient->trashed()) {
                    $patient->forceDelete();
                } else {
                    $patient->delete();
                }

                $deletedCount++;

                Log::info('[SyncEngine] Synced pending delete', [
                    'uuid' => $patient->uuid,
                ]);
            } catch (\Throwable $e) {
                if ($e->getCode() === 404 || str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'Not Found')) {
                    // Already deleted on server, or never existed. Delete locally.
                    if ($patient->trashed()) {
                        $patient->forceDelete();
                    } else {
                        $patient->delete();
                    }
                    $deletedCount++;
                    Log::info('[SyncEngine] Assumed already deleted remotely (404)', ['uuid' => $patient->uuid]);
                } else {
                    Log::warning('[SyncEngine] Failed to sync delete: ' . $e->getMessage(), [
                        'uuid' => $patient->uuid,
                    ]);
                }
            }
        }

        return $deletedCount;
    }

    /**
     * Sync pending visits to the remote server.
     *
     * Visits created offline (sync_status = 'pending_create') are uploaded
     * to the production API via POST /patients/{uuid}/visits.
     * Visits updated offline → PUT.
     * Visits deleted offline → DELETE.
     *
     * MUST run after syncPendingPatients() because visits reference patient UUIDs.
     *
     * @return array{uploaded: int, updated: int, deleted: int, failed: int}
     */
    public function syncPendingVisits(): array
    {
        $results = ['uploaded' => 0, 'updated' => 0, 'deleted' => 0, 'failed' => 0];

        // ── Step 1: Sync pending_create visits ───────────────────────────
        $pendingCreate = \App\Domains\Patients\Models\PatientVisit::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )
            ->where('sync_status', 'pending_create')
            ->with(['patient' => fn($q) => $q->withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)])
            ->orderBy('created_at', 'asc')
            ->take(200)
            ->get();

        foreach ($pendingCreate as $visit) {
            $patient = $visit->patient;
            // Skip if patient is not yet synced to the server
            if (!$patient || ($patient->sync_status ?? 'synced') !== 'synced') {
                Log::debug('[SyncEngine] Skipping visit — patient not synced yet', [
                    'visit_uuid'    => $visit->uuid,
                    'patient_status'=> $patient ? ($patient->sync_status ?? 'null') : 'NOT_FOUND',
                ]);
                continue;
            }

            try {
                $payload = collect($visit->toArray())
                    ->except(['id', 'sync_status', 'remote_uuid', 'client_updated_at', 'deleted_at'])
                    ->toArray();

                $response = $this->api->post(
                    "/patients/{$patient->uuid}/visits",
                    $payload
                );

                $remoteUuid = $response['uuid'] ?? $response['data']['uuid'] ?? null;
                if (!$remoteUuid) {
                    throw new \RuntimeException('No UUID in server response for visit create');
                }

                \App\Domains\Patients\Models\PatientVisit::where('uuid', $visit->uuid)
                    ->update([
                        'sync_status' => 'synced',
                        'remote_uuid' => $remoteUuid,
                        'updated_at'  => now(),
                    ]);

                $results['uploaded']++;

                Log::info('[SyncEngine] Visit synced (create)', [
                    'local_uuid'  => $visit->uuid,
                    'remote_uuid' => $remoteUuid,
                    'patient'     => $patient->uuid,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[SyncEngine] Visit create sync failed: ' . $e->getMessage(), [
                    'visit_uuid' => $visit->uuid,
                    'patient'    => $patient?->uuid,
                ]);
                \App\Domains\Patients\Models\PatientVisit::where('uuid', $visit->uuid)
                    ->update([
                        'sync_status'   => 'failed',
                        'updated_at'    => now(),
                    ]);
                $results['failed']++;
            }
        }

        // ── Step 2: Sync pending_update visits ──────────────────────────
        $pendingUpdate = \App\Domains\Patients\Models\PatientVisit::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )
            ->where('sync_status', 'pending_update')
            ->with(['patient' => fn($q) => $q->withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)])
            ->orderBy('created_at', 'asc')
            ->take(200)
            ->get();

        foreach ($pendingUpdate as $visit) {
            $patient = $visit->patient;
            if (!$patient || ($patient->sync_status ?? 'synced') !== 'synced') {
                continue;
            }

            try {
                // If no remote_uuid, the visit was never synced — convert to create
                if (empty($visit->remote_uuid)) {
                    \App\Domains\Patients\Models\PatientVisit::where('uuid', $visit->uuid)
                        ->update(['sync_status' => 'pending_create', 'updated_at' => now()]);
                    Log::info('[SyncEngine] Visit has no remote_uuid, converted pending_update to pending_create', [
                        'visit_uuid' => $visit->uuid,
                    ]);
                    continue;
                }

                $payload = collect($visit->toArray())
                    ->except(['id', 'sync_status', 'remote_uuid', 'client_updated_at', 'deleted_at', 'uuid'])
                    ->toArray();

                $this->api->put(
                    "/patients/{$patient->uuid}/visits/{$visit->remote_uuid}",
                    $payload
                );

                \App\Domains\Patients\Models\PatientVisit::where('uuid', $visit->uuid)
                    ->update([
                        'sync_status' => 'synced',
                        'updated_at'  => now(),
                    ]);

                $results['updated']++;

                Log::info('[SyncEngine] Visit synced (update)', [
                    'local_uuid'  => $visit->uuid,
                    'remote_uuid' => $visit->remote_uuid,
                    'patient'     => $patient->uuid,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[SyncEngine] Visit update sync failed: ' . $e->getMessage(), [
                    'visit_uuid' => $visit->uuid,
                    'patient'    => $patient?->uuid,
                ]);
                \App\Domains\Patients\Models\PatientVisit::where('uuid', $visit->uuid)
                    ->update(['sync_status' => 'failed', 'updated_at' => now()]);
                $results['failed']++;
            }
        }

        // ── Step 3: Sync pending_delete visits ──────────────────────────
        $pendingDelete = \App\Domains\Patients\Models\PatientVisit::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )
            ->where('sync_status', 'pending_delete')
            ->with(['patient' => fn($q) => $q->withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)])
            ->orderBy('created_at', 'asc')
            ->take(200)
            ->get();

        foreach ($pendingDelete as $visit) {
            try {
                $patient = $visit->patient;
                // If patient exists, try to delete from server
                if ($patient && !empty($visit->remote_uuid)) {
                    try {
                        $this->api->delete("/patients/{$patient->uuid}/visits/{$visit->remote_uuid}");
                    } catch (\Throwable $e) {
                        // 404 = already deleted — treat as success
                        if (!($e->getCode() === 404 || str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'Not Found'))) {
                            throw $e;
                        }
                        Log::info('[SyncEngine] Visit assumed already deleted remotely (404)', ['uuid' => $visit->uuid]);
                    }
                }

                // Force delete locally
                $visit->forceDelete();
                $results['deleted']++;

                Log::info('[SyncEngine] Visit deleted remotely', ['uuid' => $visit->uuid]);
            } catch (\Throwable $e) {
                Log::warning('[SyncEngine] Visit delete sync failed: ' . $e->getMessage(), [
                    'visit_uuid' => $visit->uuid,
                ]);
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Process pending notes — sync note delete operations to the remote server.
     * Notes created offline (sync_status = 'pending_create') are uploaded
     * to the production API via POST /patients/{uuid}/notes.
     * Notes marked as pending_delete are deleted remotely.
     *
     * @return array{uploaded: int, deleted: int, failed: int}
     */
    public function syncPendingNotes(): array
    {
        $results = ['uploaded' => 0, 'deleted' => 0, 'failed' => 0];

        // ═══ SYNC-007 FIX: Bypass DoctorIsolationScope ═══════════════════
        // The DoctorIsolationScope filters notes by primary_doctor_id.
        // Sync engine must see ALL pending notes regardless of doctor.
        // Same pattern used in EloquentPatientNoteRepository::forPatient().
        $pendingNotes = \App\Domains\Patients\Models\PatientNote::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )
            ->where('sync_status', 'pending_create')
            ->with(['patient' => fn($q) => $q->withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)])
            ->orderBy('created_at', 'asc')
            ->take(200)
            ->get();

        foreach ($pendingNotes as $note) {
            $patient = $note->patient;
            if (!$patient || ($patient->sync_status ?? 'synced') !== 'synced') {
                continue;
            }

            try {
                $response = $this->api->post(
                    "/patients/{$patient->uuid}/notes",
                    [
                        'content'  => $note->content,
                        'category' => $note->category ?? 'general',
                    ]
                );

                $remoteUuid = $response['uuid'] ?? $response['data']['uuid'] ?? null;
                if (!$remoteUuid) {
                    throw new \RuntimeException('No UUID in server response for note');
                }

                \App\Domains\Patients\Models\PatientNote::where('uuid', $note->uuid)
                    ->update([
                        'sync_status' => 'synced',
                        'remote_uuid' => $remoteUuid,
                        'updated_at'  => now(),
                    ]);

                $results['uploaded']++;

                Log::info('[SyncEngine] Note synced successfully', [
                    'local_uuid'  => $note->uuid,
                    'remote_uuid' => $remoteUuid,
                    'patient'     => $patient->uuid,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[SyncEngine] Note sync failed: ' . $e->getMessage(), [
                    'note_uuid' => $note->uuid,
                    'patient'   => $patient?->uuid,
                ]);
                \App\Domains\Patients\Models\PatientNote::where('uuid', $note->uuid)
                    ->update([
                        'sync_status'   => 'failed',
                        'error_message' => $e->getMessage(),
                        'updated_at'    => now(),
                    ]);
                $results['failed']++;
            }
        }

        // ═══ SYNC-006 FIX: Add pending_update handling ═══════════════════
        // Notes with sync_status = 'pending_update' need to be uploaded
        // to the production server via PUT (not POST like pending_create).
        $pendingUpdateNotes = \App\Domains\Patients\Models\PatientNote::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )
            ->where('sync_status', 'pending_update')
            ->with(['patient' => fn($q) => $q->withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)])
            ->orderBy('created_at', 'asc')
            ->take(200)
            ->get();

        foreach ($pendingUpdateNotes as $note) {
            $patient = $note->patient;
            if (!$patient || ($patient->sync_status ?? 'synced') !== 'synced') {
                continue;
            }

            try {
                // If the note has never been synced (no remote_uuid),
                // we cannot PUT to the production server because the note
                // doesn't exist there yet. Convert to pending_create instead.
                if (empty($note->remote_uuid)) {
                    \App\Domains\Patients\Models\PatientNote::where('uuid', $note->uuid)
                        ->update([
                            'sync_status' => 'pending_create',
                            'updated_at'  => now(),
                        ]);
                    Log::info('[SyncEngine] Note has no remote_uuid, converted pending_update to pending_create', [
                        'note_uuid' => $note->uuid,
                    ]);
                    continue;
                }

                $remoteUuid = $note->remote_uuid;
                $response = $this->api->put(
                    "/patients/{$patient->uuid}/notes/{$remoteUuid}",
                    [
                        'content' => $note->content,
                    ]
                );

                \App\Domains\Patients\Models\PatientNote::where('uuid', $note->uuid)
                    ->update([
                        'sync_status' => 'synced',
                        'updated_at'  => now(),
                    ]);

                $results['uploaded']++;

                Log::info('[SyncEngine] Note update synced successfully', [
                    'local_uuid'  => $note->uuid,
                    'remote_uuid' => $remoteUuid,
                    'patient'     => $patient->uuid,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[SyncEngine] Note update sync failed: ' . $e->getMessage(), [
                    'note_uuid' => $note->uuid,
                    'patient'   => $patient?->uuid,
                ]);
                \App\Domains\Patients\Models\PatientNote::where('uuid', $note->uuid)
                    ->update([
                        'sync_status'   => 'failed',
                        'error_message' => $e->getMessage(),
                        'updated_at'    => now(),
                    ]);
                $results['failed']++;
            }
        }

        // ── Step 2: Sync pending_delete notes ───────────────────────────
        // ═══ SYNC-007 FIX: Bypass DoctorIsolationScope ═══════════════════
        $deleteNotes = \App\Domains\Patients\Models\PatientNote::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )
            ->where('sync_status', 'pending_delete')
            ->with(['patient' => fn($q) => $q->withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)])
            ->orderBy('created_at', 'asc')
            ->take(200)
            ->get();

        foreach ($deleteNotes as $note) {
            try {
                $remoteUuid = $note->remote_uuid ?? $note->uuid;
                $this->api->delete("/patients/{$note->patient->uuid}/notes/{$remoteUuid}");

                $note->forceDelete();
                $results['deleted']++;

                Log::info('[SyncEngine] Note deleted remotely', [
                    'uuid' => $note->uuid,
                ]);
            } catch (\Throwable $e) {
                if ($e->getCode() === 404 || str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'Not Found')) {
                    $note->forceDelete();
                    $results['deleted']++;
                    Log::info('[SyncEngine] Assumed note already deleted remotely (404)', ['uuid' => $note->uuid]);
                } else {
                    Log::warning('[SyncEngine] Note delete sync failed: ' . $e->getMessage(), [
                        'note_uuid' => $note->uuid,
                    ]);
                    $results['failed']++;
                }
            }
        }

        return $results;
    }

    /**
     * Process pending file deletes — delete files from the production server.
     * Files with sync_status = 'pending_delete' in patient_files table.
     *
     * @return int Number of successfully deleted files
     */
    public function processPendingFileDeletes(): int
    {
        $deletedCount = 0;

        // ═══ SYNC-008 FIX: Use withTrashed() ═════════════════════════════
        // FileController::destroy() calls $file->update(['sync_status' =>
        // 'pending_delete']) BEFORE calling $file->delete(). So the record
        // is soft-deleted AND marked pending_delete. We must use withTrashed()
        // to find these records.
        $pendingDeletes = \App\Domains\Media\Models\PatientFile::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )
            ->withTrashed()
            ->where('sync_status', 'pending_delete')
            ->with(['patient' => fn($q) => $q->withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)])
            ->get();

        foreach ($pendingDeletes as $file) {
            try {
                $patient = $file->patient;
                if (!$patient) {
                    // Patient not found — just delete the local record
                    $file->forceDelete();
                    $deletedCount++;
                    continue;
                }

                // Delete from production server
                $this->api->delete("/files/{$file->uuid}");

                // Remove from local SQLite entirely
                $file->forceDelete();
                $deletedCount++;

                Log::info('[SyncEngine] File deleted remotely', [
                    'uuid' => $file->uuid,
                    'patient' => $patient->uuid,
                ]);
            } catch (\Throwable $e) {
                if ($e->getCode() === 404 || str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'Not Found')) {
                    $file->forceDelete();
                    $deletedCount++;
                    Log::info('[SyncEngine] Assumed file already deleted remotely (404)', ['uuid' => $file->uuid]);
                } else {
                    Log::warning('[SyncEngine] File delete sync failed: ' . $e->getMessage(), [
                        'file_uuid' => $file->uuid,
                    ]);
                }
            }
        }

        return $deletedCount;
    }

    /**
     * Process pending file updates — update file metadata on the production server.
     * Files with sync_status = 'pending_update' in patient_files table.
     *
     * @return int Number of successfully updated files
     */
    public function processPendingFileUpdates(): int
    {
        $updatedCount = 0;

        // ═══ SYNC-008 FIX: Use withTrashed() ═════════════════════════════
        // Same as processPendingFileDeletes — records may be soft-deleted.
        $pendingUpdates = \App\Domains\Media\Models\PatientFile::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )
            ->withTrashed()
            ->where('sync_status', 'pending_update')
            ->with(['patient' => fn($q) => $q->withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)])
            ->get();

        foreach ($pendingUpdates as $file) {
            try {
                $patient = $file->patient;
                if (!$patient || ($patient->sync_status ?? 'synced') !== 'synced') {
                    continue;
                }

                // Update metadata on production server
                $this->api->put("/files/{$file->uuid}", [
                    'title' => $file->title,
                    'desc' => $file->desc,
                    'category' => $file->category,
                ]);

                // Mark as synced
                \App\Domains\Media\Models\PatientFile::where('uuid', $file->uuid)
                    ->update([
                        'sync_status' => 'synced',
                        'updated_at' => now(),
                    ]);

                $updatedCount++;

                Log::info('[SyncEngine] File update synced successfully', [
                    'uuid' => $file->uuid,
                    'patient' => $patient->uuid,
                ]);
            } catch (\Throwable $e) {
                \App\Domains\Media\Models\PatientFile::where('uuid', $file->uuid)
                    ->update([
                        'sync_status' => 'failed',
                    ]);
                Log::warning('[SyncEngine] File update sync failed: ' . $e->getMessage(), [
                    'file_uuid' => $file->uuid,
                ]);
            }
        }

        return $updatedCount;
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

        $pendingNoteCount = \App\Domains\Patients\Models\PatientNote::whereIn(
            'sync_status', ['pending_create', 'pending_delete']
        )->count();

        // ── Include visits in pending check ──────────────────────────────
        $pendingVisitCount = \App\Domains\Patients\Models\PatientVisit::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )->whereIn('sync_status', ['pending_create', 'pending_update', 'pending_delete'])
            ->count();

        // ═══ SYNC-008 FIX: Include patient_files in pending check ═══════════
        $pendingPatientFileCount = \App\Domains\Media\Models\PatientFile::whereIn(
            'sync_status', ['pending_delete', 'pending_update']
        )->orWhere(function ($q) {
            $q->whereNull('remote_uuid')->where('upload_status', 'ready');
        })->count();

        return ($pendingPatientCount + $pendingFileCount + $pendingNoteCount + $pendingVisitCount + $pendingPatientFileCount) > 0;
    }

    /**
     * Get a summary of all pending operations.
     * Used by the frontend to show sync status.
     *
     * @return array{patients: int, files: int, notes: int, visits: int, deletes: int, total: int}
     */
    public function getPendingSummary(): array
    {
        $patientCreate = \App\Domains\Patients\Models\Patient::where('sync_status', 'pending_create')->count();
        $patientUpdate = \App\Domains\Patients\Models\Patient::where('sync_status', 'pending_update')->count();
        $patientDelete = \App\Domains\Patients\Models\Patient::where('sync_status', 'pending_delete')->count();
        $filesPending  = DB::table('offline_files')->whereIn('sync_status', ['pending_upload', 'failed'])->count();
        $notesPending  = \App\Domains\Patients\Models\PatientNote::whereIn('sync_status', ['pending_create', 'pending_delete'])->count();

        // ── Include visits in summary ─────────────────────────────────────
        $visitsPending = \App\Domains\Patients\Models\PatientVisit::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )->whereIn('sync_status', ['pending_create', 'pending_update', 'pending_delete'])
            ->count();

        // ═══ SYNC-008 FIX: Include patient_files in summary ═══════════════
        $patientFilesPending = \App\Domains\Media\Models\PatientFile::whereIn(
            'sync_status', ['pending_delete', 'pending_update']
        )->orWhere(function ($q) {
            $q->whereNull('remote_uuid')->where('upload_status', 'ready');
        })->count();

        return [
            'patients' => $patientCreate + $patientUpdate,
            'deletes'  => $patientDelete,
            'files'    => $filesPending + $patientFilesPending,
            'notes'    => $notesPending,
            'visits'   => $visitsPending,
            'total'    => $patientCreate + $patientUpdate + $patientDelete + $filesPending + $patientFilesPending + $notesPending + $visitsPending,
        ];
    }
}
