<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use App\Domains\Patients\Models\Patient;
use ZipArchive;

class ExportPatientFilesJob implements ShouldQueue
{
    use Queueable;

    public $patient;
    public $jobId;

    /**
     * Create a new job instance.
     */
    public function __construct(Patient $patient, string $jobId)
    {
        $this->patient = $patient;
        $this->jobId = $jobId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Cache::put("export_patient_files_{$this->jobId}", ['status' => 'processing', 'progress' => 0], 3600);

        $files = $this->patient->files;
        $notes = $this->patient->notes;
        
        if ($files->isEmpty() && $notes->isEmpty()) {
            Cache::put("export_patient_files_{$this->jobId}", ['status' => 'error', 'message' => 'No files or notes found'], 3600);
            return;
        }

        // Per-JOB filename, not per-patient. The old fixed
        // "patient_{uuid}_files.zip" was shared by every export of the same
        // patient, so two exports running at once wrote over each other and a
        // finished download deleted the file a still-running one was about to
        // serve. It also made the jobId in the download URL decorative.
        $zipName = self::zipNameFor($this->patient->uuid, $this->jobId);
        $zipPath = Storage::disk('local')->path($zipName);

        Storage::disk('local')->makeDirectory(self::EXPORT_DIR);
        $this->purgeStaleExports();

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            // Add Files
            $seen = [];
            foreach ($files as $file) {
                // existingAbsolutePath(), not file_path: some rows name a file
                // that was never written while the bytes sit beside it under
                // another of the row's names (see PatientFile). Those files
                // were silently missing from every zip.
                $filePath = $file->existingAbsolutePath();
                if (!$filePath) {
                    continue;
                }

                // Distinct entry names — a zip cannot hold two entries with
                // the same path, and file_name is not unique across a patient
                // (repeated "IMG_0574.jpg" etc). Without this the duplicates
                // were dropped from the archive.
                $entry = $file->file_name ?: basename($filePath);
                $n = 1;
                $candidate = $entry;
                while (isset($seen[strtolower($candidate)])) {
                    $ext  = pathinfo($entry, PATHINFO_EXTENSION);
                    $base = pathinfo($entry, PATHINFO_FILENAME);
                    $candidate = $base . '_' . (++$n) . ($ext ? '.' . $ext : '');
                }
                $seen[strtolower($candidate)] = true;

                $zip->addFile($filePath, 'Files/' . $candidate);
            }

            // Add Notes
            foreach ($notes as $index => $note) {
                $noteDate = $note->created_at ? $note->created_at->format('Y-m-d_H-i-s') : 'Note_' . ($index + 1);
                $zip->addFromString('Notes/Note_' . $noteDate . '.txt', $note->content ?? '');
            }
            
            $zip->close();

            Cache::put("export_patient_files_{$this->jobId}", [
                'status' => 'completed',
                // Recorded so downloadZip() serves exactly the archive THIS
                // job produced instead of guessing at a shared filename.
                'path'   => $zipName,
                'size'   => Storage::disk('local')->exists($zipName) ? Storage::disk('local')->size($zipName) : 0,
                'url'    => route('api.patients.download_zip', ['jobId' => $this->jobId, 'patient' => $this->patient->uuid]),
            ], 3600);
        } else {
            Cache::put("export_patient_files_{$this->jobId}", ['status' => 'error', 'message' => 'Could not create zip'], 3600);
        }
    }

    /** Directory holding generated export archives, relative to the local disk. */
    public const EXPORT_DIR = 'exports';

    public static function zipNameFor(string $patientUuid, string $jobId): string
    {
        return self::EXPORT_DIR . "/patient_{$patientUuid}_{$jobId}.zip";
    }

    /**
     * Drop archives older than the cache entry that points at them.
     *
     * Exports are no longer deleted the moment they are sent — doing that
     * broke the ordinary things a browser does with a large download (a
     * second connection, a Range request, a retry, a resume): the first
     * response consumed the file and every follow-up got a 404 while the
     * status endpoint still advertised the URL as ready. Keeping them and
     * sweeping on the next export is what makes those retries work.
     */
    private function purgeStaleExports(): void
    {
        try {
            $cutoff = now()->subHours(6)->getTimestamp();
            foreach (Storage::disk('local')->files(self::EXPORT_DIR) as $path) {
                if (str_ends_with($path, '.zip') && Storage::disk('local')->lastModified($path) < $cutoff) {
                    Storage::disk('local')->delete($path);
                }
            }
        } catch (\Throwable $e) {
            // Housekeeping must never fail the export itself.
            \Illuminate\Support\Facades\Log::warning('[ExportPatientFilesJob] purge failed: ' . $e->getMessage());
        }
    }
}
