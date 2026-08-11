<?php

namespace App\Services\Offline;

use App\Domains\Offline\Models\OfflinePackage;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use App\Domains\Patients\Models\PatientVisit;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Auth\Scopes\DoctorIsolationScope;
use App\Repositories\OfflinePackageRepository;
use App\Services\Mobile\RemoteApiService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * OfflinePackageService — explicit per-patient offline snapshot
 * ───────────────────────────────────────────────────────────────────────────
 *
 * Deliberately independent of the old sync system (SyncEngineService,
 * SyncQueueService, sync_status pending_* writes) — this is a one-way pull
 * from production into local SQLite, triggered only by an explicit user
 * action, not a bidirectional queue. It writes directly via Eloquent models
 * (bypassing PatientRepository and the other Phase 2 offline-write guards,
 * which exist to block *implicit* local-first writes — this is the one
 * sanctioned, explicit SQLite write path).
 *
 * The payload lives in the existing patients/patient_notes/patient_visits/
 * patient_files tables (same shape as production) with sync_status='synced'
 * on every row, and file bytes are stored at the exact same
 * patients/{uuid}/{fileUuid}.{ext} path a normal upload would use — this is
 * what lets PatientFile::getUrlAttribute() and FileAccessController's
 * existing resolveAbsolutePath()/streamDirect() serve them online AND
 * offline with zero changes, and keeps them automatically outside the
 * file_cache LRU's eviction reach (a completely separate table/directory).
 * ───────────────────────────────────────────────────────────────────────────
 */
class OfflinePackageService
{
    private const MAX_PAGES = 50;
    private const PER_PAGE = 50;

    public function __construct(
        private readonly RemoteApiService $remote,
        private readonly OfflinePackageRepository $packages,
    ) {}

    public function download(string $patientUuid, int $ownerUserId): OfflinePackage
    {
        return $this->reconcile($patientUuid, $ownerUserId, isInitialDownload: true);
    }

    public function refresh(string $patientUuid, int $ownerUserId): OfflinePackage
    {
        return $this->reconcile($patientUuid, $ownerUserId, isInitialDownload: false);
    }

    public function list(int $ownerUserId): array
    {
        $packages = $this->packages->listForOwner($ownerUserId);

        return array_map(function ($row) {
            $patient = Patient::withoutGlobalScope(DoctorIsolationScope::class)
                ->where('uuid', $row['patient_uuid'])
                ->first(['uuid', 'name', 'code']);

            return array_merge($row, [
                'patient_name' => $patient?->name,
                'patient_code' => $patient?->code,
            ]);
        }, $packages);
    }

    public function delete(string $patientUuid, int $ownerUserId): void
    {
        $patient = Patient::withoutGlobalScope(DoctorIsolationScope::class)
            ->where('uuid', $patientUuid)
            ->first();

        if ($patient) {
            $files = PatientFile::withoutGlobalScope(DoctorIsolationScope::class)
                ->where('patient_id', $patient->id)
                ->get();

            foreach ($files as $file) {
                if ($file->file_path) {
                    Storage::disk('local')->delete($file->file_path);
                }
            }

            PatientFile::withoutGlobalScope(DoctorIsolationScope::class)->where('patient_id', $patient->id)->forceDelete();
            PatientNote::withoutGlobalScope(DoctorIsolationScope::class)->where('patient_id', $patient->id)->forceDelete();
            PatientVisit::withoutGlobalScope(DoctorIsolationScope::class)->where('patient_id', $patient->id)->forceDelete();
            $patient->forceDelete();
        }

        $this->packages->delete($patientUuid, $ownerUserId);
    }

    private function reconcile(string $patientUuid, int $ownerUserId, bool $isInitialDownload): OfflinePackage
    {
        // A patient with a large video can easily take several minutes to
        // pull in full (see RemoteApiService::download()'s raised timeout).
        // The embedded runtime's default max_execution_time would otherwise
        // fatally kill this whole request partway through — not a graceful
        // per-file failure, the entire download just dies. 0 = unlimited;
        // this request is explicitly user-initiated and long-running by
        // nature, not a runaway loop.
        @set_time_limit(0);

        $package = $this->packages->findOrCreate($patientUuid, $ownerUserId);
        $package = $this->packages->updateStatus($package, OfflinePackage::STATUS_DOWNLOADING, ['error' => null]);

        try {
            $localPatient = $this->pullPatientCore($patientUuid, $ownerUserId);
            $this->pullNotes($patientUuid, $localPatient, $ownerUserId);
            $this->pullVisits($patientUuid, $localPatient);
            $fileErrors = $this->pullFiles($patientUuid, $localPatient, $ownerUserId);

            $package = $this->packages->updateStatus($package, OfflinePackage::STATUS_VERIFYING);
            $verifyError = $this->verify($localPatient);

            $error = $fileErrors ?: $verifyError;

            if ($error) {
                return $this->packages->updateStatus($package, OfflinePackage::STATUS_FAILED, ['error' => $error]);
            }

            return $this->packages->updateStatus($package, OfflinePackage::STATUS_READY, [
                'error' => null,
                'downloaded_at' => $package->downloaded_at ?? now(),
                'last_refreshed_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('[OfflinePackageService] reconcile failed', [
                'patient_uuid' => $patientUuid,
                'initial_download' => $isInitialDownload,
                'exception' => $e->getMessage(),
            ]);

            // Leave whatever local data already reconciled successfully in
            // place (resumable) — only the package's own status reflects the
            // failure, existing offline data for this patient is untouched.
            return $this->packages->updateStatus($package, OfflinePackage::STATUS_FAILED, [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function pullPatientCore(string $patientUuid, int $ownerUserId): Patient
    {
        $response = $this->remote->get("/patients/{$patientUuid}");
        $data = $response['data'] ?? $response;

        return Patient::withoutGlobalScope(DoctorIsolationScope::class)->updateOrCreate(
            ['uuid' => $patientUuid],
            [
                'code' => $data['code'] ?? null,
                'name' => $data['name'] ?? 'Unknown',
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'diagnosis' => $data['diagnosis'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'gender' => $data['gender'] ?? null,
                'blood_group' => $data['blood_group'] ?? null,
                'weight' => $data['weight'] ?? null,
                'height' => $data['height'] ?? null,
                'allergies' => $data['allergies'] ?? null,
                'chronic_diseases' => $data['chronic_diseases'] ?? null,
                'medical_status' => $data['medical_status'] ?? null,
                'medical_record_number' => $data['medical_record_number'] ?? null,
                'primary_doctor_id' => $ownerUserId,
                'created_by_id' => $ownerUserId,
                'sync_status' => 'synced',
            ]
        );
    }

    private function pullNotes(string $patientUuid, Patient $localPatient, int $ownerUserId): void
    {
        $seenUuids = [];

        foreach ($this->paginate("/patients/{$patientUuid}/notes") as $item) {
            $seenUuids[] = $item['uuid'];

            PatientNote::withoutGlobalScope(DoctorIsolationScope::class)->updateOrCreate(
                ['uuid' => $item['uuid']],
                [
                    'patient_id' => $localPatient->id,
                    // Server author_id is a foreign-key ID on production's
                    // users table, meaningless (and FK-unsafe) locally —
                    // attribute the snapshot to whoever downloaded it.
                    'author_id' => $ownerUserId,
                    'category' => $item['category'] ?? 'notes',
                    'content' => $item['content'] ?? '',
                    'sync_status' => 'synced',
                ]
            );
        }

        // Reconciliation: a note that no longer appears on the server (e.g.
        // deleted by another doctor) must not linger in the local package.
        PatientNote::withoutGlobalScope(DoctorIsolationScope::class)
            ->where('patient_id', $localPatient->id)
            ->whereNotIn('uuid', $seenUuids)
            ->forceDelete();
    }

    private function pullVisits(string $patientUuid, Patient $localPatient): void
    {
        $seenUuids = [];

        foreach ($this->paginate("/patients/{$patientUuid}/visits") as $item) {
            $seenUuids[] = $item['uuid'];

            PatientVisit::withoutGlobalScope(DoctorIsolationScope::class)->updateOrCreate(
                ['uuid' => $item['uuid']],
                [
                    'patient_id' => $localPatient->id,
                    'visit_type' => $item['visit_type'] ?? null,
                    'visit_type_custom' => $item['visit_type_custom'] ?? null,
                    'reason' => $item['reason'] ?? null,
                    'reason_custom' => $item['reason_custom'] ?? null,
                    'visit_date' => $item['visit_date'] ?? null,
                    'visit_time' => $item['visit_time'] ?? null,
                    'session_details' => $item['session_details'] ?? null,
                    'diagnosis' => $item['diagnosis'] ?? null,
                    'prescription' => $item['prescription'] ?? null,
                    'next_visit_date' => $item['next_visit_date'] ?? null,
                    'cost' => $item['cost'] ?? null,
                    'sync_status' => 'synced',
                ]
            );
        }

        PatientVisit::withoutGlobalScope(DoctorIsolationScope::class)
            ->where('patient_id', $localPatient->id)
            ->whereNotIn('uuid', $seenUuids)
            ->forceDelete();
    }

    /**
     * Pull file metadata + bytes. Returns an error string if any file's
     * bytes failed to download, or null if everything succeeded (a package
     * cannot become READY while any of its files are missing bytes).
     */
    private function pullFiles(string $patientUuid, Patient $localPatient, int $ownerUserId): ?string
    {
        $seenUuids = [];
        $failures = [];
        // endpoint => [destinationPath, displayName] — downloaded concurrently
        // in one batch after the metadata loop below, instead of one blocking
        // HTTP round trip per file (see RemoteApiService::downloadMany()).
        $toDownload = [];

        foreach ($this->paginate("/patients/{$patientUuid}/files") as $item) {
            $fileUuid = $item['uuid'];
            $seenUuids[] = $fileUuid;

            $extension = pathinfo($item['file_name'] ?? '', PATHINFO_EXTENSION) ?: 'bin';
            $relativePath = "patients/{$patientUuid}/{$fileUuid}.{$extension}";

            // file_path/mime_type/size are NOT NULL columns (patient_files
            // migration) — must be present on the very first insert, not
            // patched in afterward, or updateOrCreate()'s create() throws a
            // NOT NULL constraint violation.
            PatientFile::withoutGlobalScope(DoctorIsolationScope::class)->updateOrCreate(
                ['uuid' => $fileUuid],
                [
                    'remote_uuid' => $fileUuid,
                    'patient_id' => $localPatient->id,
                    'uploaded_by_id' => $ownerUserId,
                    'title' => $item['title'] ?? ($item['file_name'] ?? null),
                    'desc' => $item['description'] ?? null,
                    'type' => $item['type'] ?? 'document',
                    'category' => $item['category'] ?? 'notes',
                    'date' => $item['date'] ?? null,
                    'file_name' => $item['file_name'] ?? $fileUuid,
                    'file_path' => $relativePath,
                    'mime_type' => $item['mime_type'] ?? 'application/octet-stream',
                    'size' => $item['size'] ?? 0,
                    'upload_status' => 'ready',
                    'sync_status' => 'synced',
                ]
            );

            $alreadyOnDisk = Storage::disk('local')->exists($relativePath)
                && Storage::disk('local')->size($relativePath) > 0;

            if (!$alreadyOnDisk) {
                $toDownload["/files/{$fileUuid}"] = [
                    Storage::disk('local')->path($relativePath),
                    $item['file_name'] ?? $fileUuid,
                ];
            }
        }

        if ($toDownload) {
            $results = $this->remote->downloadMany(array_map(fn ($job) => $job[0], $toDownload));
            foreach ($results as $endpoint => $ok) {
                if (!$ok) {
                    $failures[] = $toDownload[$endpoint][1];
                }
            }
        }

        // Reconciliation: a file removed on the server (e.g. another doctor
        // deleted it) must be removed from the local package too, bytes
        // included — this is the exact "B.jpg removed locally" case.
        $staleFiles = PatientFile::withoutGlobalScope(DoctorIsolationScope::class)
            ->where('patient_id', $localPatient->id)
            ->whereNotIn('uuid', $seenUuids)
            ->get();

        foreach ($staleFiles as $stale) {
            if ($stale->file_path) {
                Storage::disk('local')->delete($stale->file_path);
            }
            $stale->forceDelete();
        }

        return $failures ? ('Failed to download: ' . implode(', ', $failures)) : null;
    }

    private function verify(Patient $localPatient): ?string
    {
        $files = PatientFile::withoutGlobalScope(DoctorIsolationScope::class)
            ->where('patient_id', $localPatient->id)
            ->get(['file_name', 'file_path']);

        $missing = $files->filter(function ($file) {
            return !$file->file_path
                || !Storage::disk('local')->exists($file->file_path)
                || Storage::disk('local')->size($file->file_path) === 0;
        })->pluck('file_name');

        if ($missing->isNotEmpty()) {
            return 'Missing local bytes for: ' . $missing->implode(', ');
        }

        return null;
    }

    /**
     * Yields every item across all pages of a paginated mobile-API list
     * endpoint, stopping at the first empty page (or a safety cap).
     */
    private function paginate(string $endpoint): iterable
    {
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->remote->get($endpoint, ['page' => $page, 'per_page' => self::PER_PAGE]);
            $items = $response['data'] ?? [];

            if (empty($items)) {
                return;
            }

            foreach ($items as $item) {
                yield $item;
            }

            if (count($items) < self::PER_PAGE) {
                return;
            }
        }
    }
}
