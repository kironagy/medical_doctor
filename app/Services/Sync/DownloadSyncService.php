<?php

namespace App\Services\Sync;

use App\Services\Mobile\RemoteApiService;
use App\Domains\Patients\Models\Patient;
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

        // downloadNotes()/downloadVisits() are intentionally NOT called: they target
        // endpoints that don't exist on the server (notes and visits are exposed
        // per-patient, not globally) and they persist nothing locally. Calling them
        // only added two guaranteed-failing HTTP requests — each able to burn the
        // full 30s timeout — to every login and every "Sync Now".

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

    private function downloadPatients(array &$summary): void
    {
        try {
            $since = $this->getEntityLastSync('patients_last_sync');
            $validCols = Schema::getColumnListing('patients');

            // Remote user IDs are meaningless on-device: local SQLite only ever
            // holds this device's own user row, and patients.primary_doctor_id
            // carries an enforced FK to users. Inserting the server's doctor id
            // raises a constraint violation and the whole download is lost, so
            // ownership columns are remapped to the local user.
            $localUserId = auth()->id() ?: optional(\App\Domains\Users\Models\User::first())->id;

            $page = 1;
            do {
                $query = array_filter([
                    'since'    => $since,
                    'per_page' => 100,
                    'page'     => $page,
                ]);

                $remoteData = $this->api->get('/patients', $query);
                $remotePatients = $remoteData['data'] ?? [];
                if (!is_array($remotePatients)) {
                    break;
                }

                foreach ($remotePatients as $remoteP) {
                    if (!is_array($remoteP) || empty($remoteP['uuid'])) continue;

                    $localP = Patient::where('uuid', $remoteP['uuid'])->first();

                    // Never clobber records the device hasn't pushed yet.
                    if ($localP && $localP->sync_status !== 'synced') {
                        continue;
                    }

                    $clean = Arr::except($remoteP, ['id', 'primary_doctor', 'visits', 'shares', 'files', 'notes']);
                    $clean['sync_status'] = 'synced';
                    $clean = array_intersect_key($clean, array_flip($validCols));

                    if (array_key_exists('primary_doctor_id', $clean) || !$localP) {
                        $clean['primary_doctor_id'] = $localUserId;
                    }
                    if (array_key_exists('created_by_id', $clean)) {
                        $clean['created_by_id'] = $localUserId;
                    }

                    try {
                        Patient::unguard();
                        if (!$localP) {
                            $localP = Patient::create($clean);
                            $summary['patients']++;
                        } elseif (($remoteP['version'] ?? 1) > ($localP->version ?? 1)) {
                            $localP->update($clean);
                            $summary['patients']++;
                        }
                    } catch (Throwable $e) {
                        // One bad record must not abort the whole download.
                        Log::warning('[DownloadSyncService] Skipped patient ' . $remoteP['uuid'] . ': ' . $e->getMessage());
                    } finally {
                        Patient::reguard();
                    }

                    $patientId = $localP ? $localP->id : null;

                    // Sync files attached to this patient from the server
                    if (!empty($remoteP['files']) && is_array($remoteP['files'])) {
                        $this->syncPatientFilesFromRemote($remoteP['uuid'], $patientId, $remoteP['files'], $localUserId, $summary);
                    }

                    // Sync notes attached to this patient from the server
                    if (!empty($remoteP['notes']) && is_array($remoteP['notes'])) {
                        $this->syncPatientNotesFromRemote($remoteP['uuid'], $patientId, $remoteP['notes'], $localUserId, $summary);
                    }

                    // Sync visits attached to this patient from the server
                    if (!empty($remoteP['visits']) && is_array($remoteP['visits'])) {
                        $this->syncPatientVisitsFromRemote($remoteP['uuid'], $patientId, $remoteP['visits'], $localUserId, $summary);
                    }
                }

                $lastPage = (int) ($remoteData['last_page'] ?? 1);
                $page++;
            } while ($page <= $lastPage && $page <= 50);

            $this->setEntityLastSync('patients_last_sync', now()->toISOString());
            Log::info('[DownloadSyncService] Cached ' . $summary['patients'] . ' patients, ' . $summary['files'] . ' files, ' . $summary['notes'] . ' notes into local SQLite');
        } catch (Throwable $e) {
            Log::warning('[DownloadSyncService] Patient download failed: ' . $e->getMessage());
        }
    }

    private function syncPatientFilesFromRemote(string $patientUuid, ?int $patientId, array $remoteFiles, int $localUserId, array &$summary): void
    {
        $validCols = Schema::getColumnListing('patient_files');
        if (!$patientId) {
            $patientId = DB::table('patients')->where('uuid', $patientUuid)->value('id');
        }
        if (!$patientId) return;

        foreach ($remoteFiles as $rf) {
            if (!is_array($rf) || empty($rf['uuid'])) continue;

            $fileUuid = $rf['uuid'];
            $existing = DB::table('patient_files')
                ->where('uuid', $fileUuid)
                ->orWhere('remote_uuid', $fileUuid)
                ->first();

            // Do not overwrite local pending changes
            if ($existing && in_array($existing->sync_status, ['pending_create', 'pending_update', 'pending_delete', 'pending_sync'])) {
                continue;
            }

            $mime = $rf['mime_type'] ?? '';
            $type = $rf['type'] ?? match (true) {
                str_starts_with($mime, 'image/') => 'image',
                str_starts_with($mime, 'video/') => 'video',
                str_starts_with($mime, 'audio/') => 'audio',
                $mime === 'application/pdf' => 'pdf',
                default => 'document',
            };

            $clean = [
                'uuid'           => $fileUuid,
                'remote_uuid'    => $fileUuid,
                'patient_id'     => $patientId,
                'uploaded_by_id' => $localUserId,
                'title'          => $rf['title'] ?? $rf['file_name'] ?? 'File',
                'desc'           => $rf['desc'] ?? null,
                'type'           => $type,
                'mime_type'      => $mime ?: 'application/octet-stream',
                'size'           => (int) ($rf['size'] ?? 0),
                'category'       => $rf['category'] ?? null,
                'date'           => !empty($rf['date']) ? substr($rf['date'], 0, 10) : now()->toDateString(),
                'file_name'      => $rf['file_name'] ?? 'file',
                'file_path'      => $rf['file_path'] ?? ("patients/{$patientUuid}/{$fileUuid}"),
                'upload_status'  => 'ready',
                'sync_status'    => 'synced',
                'created_at'     => $rf['created_at'] ?? now(),
                'updated_at'     => $rf['updated_at'] ?? now(),
            ];

            $clean = array_intersect_key($clean, array_flip($validCols));

            try {
                if (!$existing) {
                    DB::table('patient_files')->insert($clean);
                    $summary['files']++;
                } else {
                    DB::table('patient_files')->where('id', $existing->id)->update($clean);
                    $summary['files']++;
                }
            } catch (Throwable $e) {
                Log::warning('[DownloadSyncService] Skipped file ' . $fileUuid . ': ' . $e->getMessage());
            }
        }
    }

    private function syncPatientNotesFromRemote(string $patientUuid, ?int $patientId, array $remoteNotes, int $localUserId, array &$summary): void
    {
        $validCols = Schema::getColumnListing('patient_notes');
        if (!$patientId) {
            $patientId = DB::table('patients')->where('uuid', $patientUuid)->value('id');
        }
        if (!$patientId) return;

        foreach ($remoteNotes as $rn) {
            if (!is_array($rn) || empty($rn['uuid'])) continue;

            $noteUuid = $rn['uuid'];
            $existing = DB::table('patient_notes')->where('uuid', $noteUuid)->first();
            if ($existing && in_array($existing->sync_status, ['pending_create', 'pending_update', 'pending_delete'])) {
                continue;
            }

            $clean = [
                'uuid'         => $noteUuid,
                'patient_id'   => $patientId,
                'doctor_id'    => $localUserId,
                'content'      => $rn['content'] ?? $rn['note'] ?? '',
                'category'     => $rn['category'] ?? null,
                'sync_status'  => 'synced',
                'created_at'   => $rn['created_at'] ?? now(),
                'updated_at'   => $rn['updated_at'] ?? now(),
            ];

            $clean = array_intersect_key($clean, array_flip($validCols));

            try {
                if (!$existing) {
                    DB::table('patient_notes')->insert($clean);
                    $summary['notes']++;
                } else {
                    DB::table('patient_notes')->where('id', $existing->id)->update($clean);
                    $summary['notes']++;
                }
            } catch (Throwable $e) {
                Log::warning('[DownloadSyncService] Skipped note ' . $noteUuid . ': ' . $e->getMessage());
            }
        }
    }

    private function syncPatientVisitsFromRemote(string $patientUuid, ?int $patientId, array $remoteVisits, int $localUserId, array &$summary): void
    {
        $validCols = Schema::getColumnListing('patient_visits');
        if (!$patientId) {
            $patientId = DB::table('patients')->where('uuid', $patientUuid)->value('id');
        }
        if (!$patientId) return;

        foreach ($remoteVisits as $rv) {
            if (!is_array($rv) || empty($rv['uuid'])) continue;

            $visitUuid = $rv['uuid'];
            $existing = DB::table('patient_visits')->where('uuid', $visitUuid)->first();
            if ($existing && in_array($existing->sync_status, ['pending_create', 'pending_update', 'pending_delete'])) {
                continue;
            }

            $clean = [
                'uuid'         => $visitUuid,
                'patient_id'   => $patientId,
                'doctor_id'    => $localUserId,
                'visit_date'   => $rv['visit_date'] ?? now()->toDateString(),
                'type'         => $rv['type'] ?? 'follow_up',
                'notes'        => $rv['notes'] ?? null,
                'sync_status'  => 'synced',
                'created_at'   => $rv['created_at'] ?? now(),
                'updated_at'   => $rv['updated_at'] ?? now(),
            ];

            $clean = array_intersect_key($clean, array_flip($validCols));

            try {
                if (!$existing) {
                    DB::table('patient_visits')->insert($clean);
                    $summary['visits']++;
                } else {
                    DB::table('patient_visits')->where('id', $existing->id)->update($clean);
                    $summary['visits']++;
                }
            } catch (Throwable $e) {
                Log::warning('[DownloadSyncService] Skipped visit ' . $visitUuid . ': ' . $e->getMessage());
            }
        }
    }

    private function downloadNotes(array &$summary): void
    {
        try {
            $since = $this->getEntityLastSync('notes_last_sync');
            $query = $since ? ['since' => $since] : [];

            $remoteData = $this->api->get('/mobile/notes', $query);
            $notes = $remoteData['data'] ?? $remoteData ?? [];

            foreach ($notes as $remoteNote) {
                if (!is_array($remoteNote) || empty($remoteNote['uuid'])) continue;
                $summary['notes']++;
            }

            $this->setEntityLastSync('notes_last_sync', now()->toISOString());
        } catch (Throwable $e) {
            // Note download endpoint optional
        }
    }

    private function downloadVisits(array &$summary): void
    {
        try {
            $since = $this->getEntityLastSync('visits_last_sync');
            $query = $since ? ['since' => $since] : [];

            $remoteData = $this->api->get('/mobile/visits', $query);
            $visits = $remoteData['data'] ?? $remoteData ?? [];

            foreach ($visits as $remoteVisit) {
                if (!is_array($remoteVisit) || empty($remoteVisit['uuid'])) continue;
                $summary['visits']++;
            }

            $this->setEntityLastSync('visits_last_sync', now()->toISOString());
        } catch (Throwable $e) {
            // Visit download endpoint optional
        }
    }
}
