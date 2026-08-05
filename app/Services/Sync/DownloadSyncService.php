<?php

namespace App\Services\Sync;

use App\Contracts\Repositories\FileCacheRepositoryInterface;
use App\Domains\Media\Models\PatientFile;
use App\Services\Mobile\RemoteApiService;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use App\Domains\Patients\Models\PatientVisit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Throwable;

class DownloadSyncService
{
    public function __construct(
        private readonly RemoteApiService $api
    ) {}

    /**
     * Perform incremental download of server changes using independent per-entity timestamps.
     */
    public function downloadChanges(): array
    {
        $summary = ['patients' => 0, 'files' => 0, 'notes' => 0, 'visits' => 0, 'categories' => 0];

        $this->downloadPatients($summary);
        $this->downloadFiles($summary);
        $this->downloadNotes($summary);
        $this->downloadVisits($summary);

        return $summary;
    }

    private function getEntityLastSync(string $entityKey): ?string
    {
        try {
            if (!Schema::hasTable('sync_states')) {
                return null;
            }
            return DB::table('sync_states')->where('key', $entityKey)->value('value');
        } catch (Throwable $e) {
            return null;
        }
    }

    private function setEntityLastSync(string $entityKey, string $timestamp): void
    {
        try {
            if (!Schema::hasTable('sync_states')) return;

            DB::table('sync_states')->updateOrInsert(
                ['key' => $entityKey],
                ['value' => $timestamp, 'updated_at' => now()]
            );
        } catch (Throwable $e) {
            // Ignore
        }
    }

    /**
     * Downloads ALL pages of the patient list, not just the first, so patients
     * beyond the first page are never silently missing from the offline cache.
     *
     * Public so it can be triggered on its own (e.g. app boot / post-login
     * bootstrap) without running the rest of the push/pull sync pipeline —
     * see BootstrapController::refreshCache().
     *
     * @return array{downloaded:int,inserted:int,updated:int}
     */
    public function downloadPatients(?array &$summary = null): array
    {
        $counts = ['downloaded' => 0, 'inserted' => 0, 'updated' => 0];
        Log::info('[PatientCache] started');

        try {
            $since = $this->getEntityLastSync('patients_last_sync');
            $cutover = now()->toISOString();
            $validCols = Schema::getColumnListing('patients');

            $page = 1;
            do {
                $query = array_filter(['since' => $since, 'per_page' => 100, 'page' => $page]);
                $remoteData = $this->api->get('/patients', $query);
                $remotePatients = $remoteData['data'] ?? $remoteData ?? [];

                foreach ($remotePatients as $remoteP) {
                    if (!is_array($remoteP) || empty($remoteP['uuid'])) continue;

                    $counts['downloaded']++;
                    $result = $this->upsertRemotePatient($remoteP, $validCols);
                    if ($result === 'inserted') {
                        $counts['inserted']++;
                        if ($summary !== null) $summary['patients']++;
                    } elseif ($result === 'updated') {
                        $counts['updated']++;
                        if ($summary !== null) $summary['patients']++;
                    }
                }

                $lastPage = (int) ($remoteData['last_page'] ?? 1);
                $page++;
            } while ($page <= $lastPage && !empty($remotePatients));

            $this->setEntityLastSync('patients_last_sync', $cutover);

            Log::info('[PatientCache] downloaded ' . $counts['downloaded'] . ' patients');
            Log::info('[PatientCache] inserted count=' . $counts['inserted']);
            Log::info('[PatientCache] updated count=' . $counts['updated']);
            Log::info('[PatientCache] SQLite patients count after sync: ' . Patient::count());
        } catch (Throwable $e) {
            Log::warning('[PatientCache] failed reason=' . $e->getMessage());
        }

        return $counts;
    }

    /**
     * Lightweight startup/refresh hydration — fetches ONLY the first patient
     * page (same page size the workspace UI uses), not the full patient list.
     * Intentionally separate from downloadPatients() (used by the full
     * SyncEngineService pipeline): this is for cheap, frequent app-boot
     * background hydration, not full sync. Never deletes local rows and
     * never overwrites a patient with unsynced local (pending_*) changes —
     * local edits always win until they've synced up.
     *
     * @return array{downloaded:int,inserted:int,updated:int}
     */
    public function cacheFirstPage(int $perPage = 10): array
    {
        $counts = ['downloaded' => 0, 'inserted' => 0, 'updated' => 0];
        Log::info('[PatientCache] cache started');

        try {
            $validCols = Schema::getColumnListing('patients');
            $remoteData = $this->api->get('/patients', ['per_page' => $perPage, 'page' => 1]);
            $remotePatients = $remoteData['data'] ?? $remoteData ?? [];

            foreach ($remotePatients as $remoteP) {
                if (!is_array($remoteP) || empty($remoteP['uuid'])) continue;

                $counts['downloaded']++;
                $result = $this->upsertRemotePatient($remoteP, $validCols);
                if ($result === 'inserted') $counts['inserted']++;
                elseif ($result === 'updated') $counts['updated']++;
            }

            Log::info('[PatientCache] downloaded page count=' . $counts['downloaded']);
            Log::info('[PatientCache] delta updates count=' . ($counts['inserted'] + $counts['updated']));
            Log::info('[PatientCache] SQLite patient count after cache: ' . Patient::count());
        } catch (Throwable $e) {
            Log::warning('[PatientCache] failed reason=' . $e->getMessage());
        }

        return $counts;
    }

    /**
     * Lightweight delta sync — fetches only patients changed (created/updated/
     * deleted) since the last successful delta sync, using a dedicated
     * `patients_delta_last_sync` cursor (kept separate from downloadPatients()'s
     * `patients_last_sync` so the two don't interfere with each other). Single
     * page only (no "download everything" loop) — a delta set is expected to
     * be small; a huge delta will simply be picked up incrementally on the
     * next call. Local pending_create/pending_update/pending_delete records
     * are never overwritten or removed (see upsertRemotePatient()).
     *
     * @return array{new:int,updated:int,deleted:int}
     */
    public function deltaSyncPatients(int $perPage = 100): array
    {
        $counts = ['new' => 0, 'updated' => 0, 'deleted' => 0];
        $since = $this->getEntityLastSync('patients_delta_last_sync');
        Log::info('[PatientDeltaSync] started last_sync=' . ($since ?? 'none'));

        try {
            $cutover = now()->toISOString();
            $validCols = Schema::getColumnListing('patients');

            $remoteData = $this->api->get('/patients', array_filter(['since' => $since, 'per_page' => $perPage]));
            $remotePatients = $remoteData['data'] ?? [];
            $deletedUuids = $remoteData['deleted'] ?? [];

            foreach ($remotePatients as $remoteP) {
                if (!is_array($remoteP) || empty($remoteP['uuid'])) continue;

                $result = $this->upsertRemotePatient($remoteP, $validCols);
                if ($result === 'inserted') $counts['new']++;
                elseif ($result === 'updated') $counts['updated']++;
            }

            foreach ($deletedUuids as $uuid) {
                $local = Patient::where('uuid', $uuid)->first();
                if ($local && $local->sync_status === 'synced') {
                    $local->forceDelete();
                    $counts['deleted']++;
                }
                // else: missing locally, or has unsynced local pending_* changes — leave as-is.
            }

            $this->setEntityLastSync('patients_delta_last_sync', $cutover);

            Log::info('[PatientDeltaSync] received new=' . $counts['new'] . ' updated=' . $counts['updated'] . ' deleted=' . $counts['deleted']);
            Log::info('[PatientDeltaSync] SQLite count after sync=' . Patient::count());
        } catch (Throwable $e) {
            Log::warning('[PatientDeltaSync] failed reason=' . $e->getMessage());
        }

        return $counts;
    }

    /**
     * Insert-or-update a single remote patient into local SQLite by UUID.
     * Local pending changes (unsynced create/update/delete) always take
     * priority and are left untouched — never overwritten by a remote pull.
     *
     * @return string 'inserted'|'updated'|'skipped'
     */
    private function upsertRemotePatient(array $remoteP, array $validCols): string
    {
        $localP = Patient::where('uuid', $remoteP['uuid'])->first();
        $clean = Arr::except($remoteP, ['id', 'primary_doctor', 'visits', 'shares', 'files', 'notes']);
        $clean['sync_status'] = 'synced';
        $clean = array_intersect_key($clean, array_flip($validCols));

        if (!$localP) {
            Patient::unguard();
            Patient::create($clean);
            Patient::reguard();
            return 'inserted';
        }

        if ($localP->sync_status !== 'synced') {
            return 'skipped'; // local pending_create/pending_update/pending_delete takes priority
        }

        if (($remoteP['version'] ?? 1) > ($localP->version ?? 1)) {
            Patient::unguard();
            $localP->update($clean);
            Patient::reguard();
            return 'updated';
        }

        return 'skipped';
    }

    /**
     * Patients whose local record changed since the given entity's last sync
     * (or ALL synced patients on a first-ever run). There is no server-side
     * "since" filter on the per-patient notes/visits endpoints, so this keeps
     * per-sync work bounded to patients that actually changed instead of
     * re-walking the entire patient list every time.
     */
    private function eligiblePatientsSince(?string $cutoff)
    {
        $query = Patient::where('sync_status', 'synced');
        if ($cutoff) {
            $query->where('updated_at', '>=', $cutoff);
        }
        return $query->get(['id', 'uuid']);
    }

    /**
     * Every patient this device has synced, with no recency filter.
     *
     * Files must NOT use eligiblePatientsSince(): that gate compares the LOCAL
     * patient row's updated_at, and uploading a file to a patient on the
     * website never touches the local patient row. So after the first sync set
     * files_last_sync, every existing patient became permanently "not
     * eligible" and its new website files were never requested from the
     * server — the file showed up in the UI (which lists from the API) with no
     * local row behind it, and previewing it 404'd.
     */
    private function syncedPatients()
    {
        return Patient::where('sync_status', 'synced')->get(['id', 'uuid']);
    }

    private function downloadNotes(array &$summary): void
    {
        $since = $this->getEntityLastSync('notes_last_sync');
        // Must match the local `updated_at` storage format (Y-m-d H:i:s), not ISO-8601 —
        // eligiblePatientsSince() compares this value against Patient::updated_at as a
        // plain string, and a format mismatch silently excludes same-day changes.
        $cutover = now()->format('Y-m-d H:i:s');

        try {
            foreach ($this->eligiblePatientsSince($since) as $patient) {
                $page = 1;
                do {
                    $remoteData = $this->api->get("/patients/{$patient->uuid}/notes", ['per_page' => 100, 'page' => $page]);
                    $notes = $remoteData['data'] ?? $remoteData ?? [];

                    foreach ($notes as $remoteNote) {
                        if (!is_array($remoteNote) || empty($remoteNote['uuid'])) continue;

                        try {
                            $clean = Arr::only($remoteNote, ['category', 'content', 'author_id']);
                            $clean['patient_id'] = $patient->id;
                            $clean['sync_status'] = 'synced';

                            PatientNote::updateOrCreate(['uuid' => $remoteNote['uuid']], $clean);
                            $summary['notes']++;
                        } catch (Throwable $e) {
                            Log::info('[DownloadSyncService] Skipped note ' . $remoteNote['uuid'] . ': ' . $e->getMessage());
                        }
                    }

                    $lastPage = (int) ($remoteData['last_page'] ?? 1);
                    $page++;
                } while ($page <= $lastPage && !empty($notes));
            }

            $this->setEntityLastSync('notes_last_sync', $cutover);
        } catch (Throwable $e) {
            Log::info('[DownloadSyncService] Note download changes skipped: ' . $e->getMessage());
        }
    }

    private function downloadVisits(array &$summary): void
    {
        $since = $this->getEntityLastSync('visits_last_sync');
        // Same format fix as downloadNotes() — must match Patient::updated_at's
        // storage format so eligiblePatientsSince()'s string comparison is valid.
        $cutover = now()->format('Y-m-d H:i:s');

        try {
            foreach ($this->eligiblePatientsSince($since) as $patient) {
                $page = 1;
                do {
                    $remoteData = $this->api->get("/patients/{$patient->uuid}/visits", ['per_page' => 100, 'page' => $page]);
                    $visits = $remoteData['data'] ?? $remoteData ?? [];

                    foreach ($visits as $remoteVisit) {
                        if (!is_array($remoteVisit) || empty($remoteVisit['uuid'])) continue;

                        try {
                            $clean = Arr::only($remoteVisit, [
                                'visit_type', 'visit_type_custom', 'reason', 'reason_custom',
                                'visit_date', 'visit_time', 'session_details', 'diagnosis',
                                'prescription', 'next_visit_date', 'cost',
                            ]);
                            $clean['patient_id'] = $patient->id;
                            $clean['sync_status'] = 'synced';

                            PatientVisit::updateOrCreate(['uuid' => $remoteVisit['uuid']], $clean);
                            $summary['visits']++;
                        } catch (Throwable $e) {
                            Log::info('[DownloadSyncService] Skipped visit ' . $remoteVisit['uuid'] . ': ' . $e->getMessage());
                        }
                    }

                    $lastPage = (int) ($remoteData['last_page'] ?? 1);
                    $page++;
                } while ($page <= $lastPage && !empty($visits));
            }

            $this->setEntityLastSync('visits_last_sync', $cutover);
        } catch (Throwable $e) {
            Log::info('[DownloadSyncService] Visit download changes skipped: ' . $e->getMessage());
        }
    }

    /**
     * Download files metadata for eligible patients and cache their physical binaries locally.
     */
    public function downloadFiles(?array &$summary = null): void
    {
        $since = $this->getEntityLastSync('files_last_sync');
        $cutover = now()->format('Y-m-d H:i:s');

        try {
            foreach ($this->syncedPatients() as $patient) {
                $this->pullPatientFiles($patient, $summary);
            }

            $this->setEntityLastSync('files_last_sync', $cutover);
        } catch (Throwable $e) {
            Log::info('[DownloadSyncService] File download changes skipped: ' . $e->getMessage());
        }
    }

    /**
     * Pull one patient's files from the server right now.
     *
     * Opening a patient calls this so a file added on the website is present
     * locally by the time its thumbnail is requested, instead of waiting for
     * the next full sync pass.
     *
     * @return int number of remote files seen
     */
    public function downloadFilesForPatient(string $patientUuid, ?array &$summary = null): int
    {
        $patient = Patient::where('uuid', $patientUuid)->first();
        if (!$patient) {
            return 0;
        }

        try {
            return $this->pullPatientFiles($patient, $summary);
        } catch (Throwable $e) {
            Log::info('[DownloadSyncService] On-demand file pull failed for ' . $patientUuid . ': ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Walk every page of a patient's remote file list and upsert each entry.
     */
    private function pullPatientFiles(Patient $patient, ?array &$summary = null): int
    {
        $seen = 0;
        $page = 1;

        do {
            $remoteData = $this->api->get("/patients/{$patient->uuid}/files", ['per_page' => 100, 'page' => $page]);
            $files = $remoteData['data'] ?? $remoteData ?? [];

            foreach ($files as $remoteFile) {
                if (!is_array($remoteFile) || empty($remoteFile['uuid'])) continue;

                $seen++;

                try {
                    $this->upsertRemoteFile($remoteFile, $patient, $summary);
                } catch (Throwable $e) {
                    Log::info('[DownloadSyncService] Skipped file ' . ($remoteFile['uuid'] ?? 'unknown') . ': ' . $e->getMessage());
                }
            }

            $lastPage = (int) ($remoteData['last_page'] ?? 1);
            $page++;
        } while ($page <= $lastPage && !empty($files));

        return $seen;
    }

    private function upsertRemoteFile(array $remoteFile, Patient $patient, ?array &$summary = null): void
    {
        $fileUuid = $remoteFile['uuid'];

        $localFile = PatientFile::withoutGlobalScope(
            \App\Domains\Auth\Scopes\DoctorIsolationScope::class
        )->where(function ($q) use ($fileUuid) {
            $q->where('uuid', $fileUuid)->orWhere('remote_uuid', $fileUuid);
        })->first();

        // A local row still waiting to be pushed wins — don't let the server's
        // older copy overwrite edits that haven't been uploaded yet.
        if ($localFile && $localFile->sync_status !== 'synced') {
            return;
        }

        // Same fragile ?? 1 pattern already proven (Bug #10, FileCacheRepository)
        // to violate patient_files.uploaded_by_id's FK on a device with 0 local
        // users — inline the same resolve-or-seed fallback instead of a bare 1.
        $uploadedById = $patient->primary_doctor_id ?? $this->resolveLocalUserId();

        // Only fall back to a default when there is no local value to preserve.
        // Blindly defaulting category to 'notes' rewrote the category of every
        // file the remote payload happened to omit it for, which is what
        // silently moved freshly uploaded files out of the category they were
        // uploaded into.
        $clean = [
            'patient_id'     => $patient->id,
            'uploaded_by_id' => $localFile->uploaded_by_id ?? $uploadedById,
            'title'          => $remoteFile['title'] ?? ($localFile->title ?? ($remoteFile['file_name'] ?? 'File')),
            'desc'           => $remoteFile['description'] ?? ($remoteFile['desc'] ?? $localFile->desc ?? null),
            'type'           => $remoteFile['type'] ?? ($localFile->type ?? 'document'),
            'category'       => !empty($remoteFile['category'])
                ? $remoteFile['category']
                : ($localFile->category ?? 'notes'),
            'date'           => $remoteFile['date'] ?? ($localFile->date ?? now()->format('Y-m-d')),
            'file_name'      => $remoteFile['file_name'] ?? ($localFile->file_name ?? 'file'),
            'mime_type'      => $remoteFile['mime_type'] ?? ($localFile->mime_type ?? 'application/octet-stream'),
            'size'           => $remoteFile['size'] ?? ($localFile->size ?? 0),
            'upload_status'  => 'ready',
            'sync_status'    => 'synced',
            'remote_uuid'    => $fileUuid,
        ];

        PatientFile::unguard();
        if (!$localFile) {
            $localFile = PatientFile::create(array_merge(['uuid' => $fileUuid, 'file_path' => ''], $clean));
        } else {
            $localFile->update($clean);
        }
        PatientFile::reguard();

        if ($summary !== null) {
            $summary['files']++;
        }

        // Auto-cache physical file locally for offline access
        try {
            if (app()->bound(FileCacheRepositoryInterface::class)) {
                app(FileCacheRepositoryInterface::class)->cache($fileUuid);
            }
        } catch (Throwable $cacheE) {
            Log::debug('[DownloadSyncService] Auto-cache file binary skipped: ' . $cacheE->getMessage(), ['uuid' => $fileUuid]);
        }
    }

    /**
     * Same fallback chain as Mobile\FileController::resolveCurrentUserId() and
     * FileCacheRepository::resolveLocalUserId(): auth user, then any local
     * user, then seed one. Needed here because a device can reach this file's
     * sync path with zero local users (see Bug #10) and a bare `?? 1` would
     * reference a users.id that doesn't exist, violating the FK on insert.
     */
    private function resolveLocalUserId(): int
    {
        $user = auth()->user();
        if ($user) {
            return $user->id;
        }

        $localUser = \App\Domains\Users\Models\User::first();
        if ($localUser) {
            return $localUser->id;
        }

        \App\Domains\Users\Models\User::unguard();
        $defaultUser = \App\Domains\Users\Models\User::firstOrCreate(
            ['email' => 'doctor@local.test'],
            [
                'name'     => 'Default Doctor',
                'password' => bcrypt('password'),
                'role'     => 'doctor',
                'uuid'     => (string) \Illuminate\Support\Str::uuid(),
                'status'   => 'active',
            ]
        );
        \App\Domains\Users\Models\User::reguard();

        return $defaultUser->id;
    }
}
