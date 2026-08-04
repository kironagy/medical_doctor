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

    private function downloadPatients(array &$summary): void
    {
        try {
            $since = $this->getEntityLastSync('patients_last_sync');
            $query = $since ? ['since' => $since] : [];

            $remoteData = $this->api->get('/patients', $query);
            $remotePatients = $remoteData['data'] ?? $remoteData ?? [];
            $validCols = Schema::getColumnListing('patients');

            foreach ($remotePatients as $remoteP) {
                if (!is_array($remoteP) || empty($remoteP['uuid'])) continue;

                $localP = Patient::where('uuid', $remoteP['uuid'])->first();
                if (!$localP) {
                    $clean = Arr::except($remoteP, ['id', 'primary_doctor', 'visits', 'shares', 'files', 'notes']);
                    $clean['sync_status'] = 'synced';
                    $clean = array_intersect_key($clean, array_flip($validCols));

                    Patient::unguard();
                    Patient::create($clean);
                    Patient::reguard();
                    $summary['patients']++;
                } else if (($remoteP['version'] ?? 1) > ($localP->version ?? 1) && $localP->sync_status === 'synced') {
                    $clean = Arr::except($remoteP, ['id', 'primary_doctor', 'visits', 'shares', 'files', 'notes']);
                    $clean['sync_status'] = 'synced';
                    $clean = array_intersect_key($clean, array_flip($validCols));

                    Patient::unguard();
                    $localP->update($clean);
                    Patient::reguard();
                    $summary['patients']++;
                }
            }

            $this->setEntityLastSync('patients_last_sync', now()->toISOString());
        } catch (Throwable $e) {
            Log::info('[DownloadSyncService] Patient download changes skipped: ' . $e->getMessage());
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
