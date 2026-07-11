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

        $zipName = "patient_{$this->patient->uuid}_files.zip";
        $zipPath = Storage::disk('local')->path($zipName);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            // Add Files
            foreach ($files as $file) {
                // Ensure correct file path relative to disk or absolute
                $filePath = Storage::disk('local')->path($file->file_path);
                if (file_exists($filePath)) {
                    $zip->addFile($filePath, 'Files/' . ($file->file_name ?? basename($filePath)));
                }
            }
            
            // Add Notes
            foreach ($notes as $index => $note) {
                $noteDate = $note->created_at ? $note->created_at->format('Y-m-d_H-i-s') : 'Note_' . ($index + 1);
                $zip->addFromString('Notes/Note_' . $noteDate . '.txt', $note->content ?? '');
            }
            
            $zip->close();
            
            Cache::put("export_patient_files_{$this->jobId}", ['status' => 'completed', 'url' => route('api.patients.download_zip', ['jobId' => $this->jobId, 'patient' => $this->patient->uuid])], 3600);
        } else {
            Cache::put("export_patient_files_{$this->jobId}", ['status' => 'error', 'message' => 'Could not create zip'], 3600);
        }
    }
}
