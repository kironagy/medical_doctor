<?php

namespace App\Services\Sync;

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
     */
    private function downloadPatients(array &$summary): void
    {
        try {
            $since = $this->getEntityLastSync('patients_last_sync');
            $validCols = Schema::getColumnListing('patients');

            $page = 1;
            do {
                $query = array_filter(['since' => $since, 'per_page' => 100, 'page' => $page]);
                $remoteData = $this->api->get('/mobile/patients', $query);
                $remotePatients = $remoteData['data'] ?? $remoteData ?? [];

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

                $lastPage = (int) ($remoteData['last_page'] ?? 1);
                $page++;
            } while ($page <= $lastPage && !empty($remotePatients));

            $this->setEntityLastSync('patients_last_sync', now()->toISOString());
        } catch (Throwable $e) {
            Log::info('[DownloadSyncService] Patient download changes skipped: ' . $e->getMessage());
        }
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

    private function downloadNotes(array &$summary): void
    {
        $since = $this->getEntityLastSync('notes_last_sync');
        $cutover = now()->toISOString();

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
        $cutover = now()->toISOString();

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
}
