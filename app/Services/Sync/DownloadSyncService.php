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
                            Patient::create($clean);
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
                }

                $lastPage = (int) ($remoteData['last_page'] ?? 1);
                $page++;
            } while ($page <= $lastPage && $page <= 50);

            $this->setEntityLastSync('patients_last_sync', now()->toISOString());
            Log::info('[DownloadSyncService] Cached ' . $summary['patients'] . ' patients into local SQLite');
        } catch (Throwable $e) {
            Log::warning('[DownloadSyncService] Patient download failed: ' . $e->getMessage());
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
