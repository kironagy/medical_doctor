<?php

namespace App\Observers;

use App\Domains\Patients\Models\Patient;
use App\Models\SyncQueueItem;
use App\Services\SyncQueueService;
use Illuminate\Support\Facades\Log;

class PatientObserver
{
    /**
     * Check if a sync operation for this record already exists.
     * Prevents duplicate sync entries.
     */
    private function hasExistingSyncQueueItem(string $recordUuid, string $operation): bool
    {
        return SyncQueueItem::where('record_uuid', $recordUuid)
            ->where('operation', $operation)
            ->where('status', 'pending')
            ->exists();
    }

    /**
     * Handle the Patient "created" event.
     */
    public function created(Patient $patient): void
    {
        // Dedup check: skip if already enqueued
        if ($this->hasExistingSyncQueueItem($patient->uuid, 'create')) {
            Log::info('[PatientObserver] Duplicate create event skipped for patient: ' . $patient->uuid);
            return;
        }

        try {
            $syncQueue = app(SyncQueueService::class);
            $syncQueue->enqueueOperation(
                entity: 'Patient',
                operation: 'create',
                recordUuid: $patient->uuid,
                payload: \Illuminate\Support\Arr::except($patient->toArray(), [
                    'id', 'primary_doctor', 'visits', 'shares', 'files', 'notes',
                    'created_at', 'updated_at', 'deleted_at',
                ]),
            );
            Log::info('[PatientObserver] Enqueued sync for new patient: ' . $patient->uuid);
        } catch (\Throwable $e) {
            Log::warning('[PatientObserver] Failed to enqueue patient create sync: ' . $e->getMessage());
        }
    }

    /**
     * Handle the Patient "updated" event.
     */
    public function updated(Patient $patient): void
    {
        // Only enqueue if meaningful fields changed (avoid redundant sync on touch)
        $relevantFields = ['name', 'phone', 'email', 'address', 'diagnosis', 'date_of_birth',
            'gender', 'blood_group', 'weight', 'height', 'allergies', 'chronic_diseases',
            'medical_status', 'medical_record_number', 'code'];
        
        $changed = $patient->getChanges();
        $hasRelevantChange = !empty(array_intersect(array_keys($changed), $relevantFields));

        if (!$hasRelevantChange) {
            return;
        }

        // Dedup check
        if ($this->hasExistingSyncQueueItem($patient->uuid, 'update')) {
            Log::info('[PatientObserver] Duplicate update event skipped for patient: ' . $patient->uuid);
            return;
        }

        try {
            $syncQueue = app(SyncQueueService::class);
            $syncQueue->enqueueOperation(
                entity: 'Patient',
                operation: 'update',
                recordUuid: $patient->uuid,
                payload: $changed,
            );
            Log::info('[PatientObserver] Enqueued sync for updated patient: ' . $patient->uuid);
        } catch (\Throwable $e) {
            Log::warning('[PatientObserver] Failed to enqueue patient update sync: ' . $e->getMessage());
        }
    }

    /**
     * Handle the Patient "deleted" event (SoftDeletes).
     * CASCADE: Also soft-delete all associated child records (files, notes, visits)
     * so their observers enqueue delete sync operations automatically.
     */
    public function deleted(Patient $patient): void
    {
        // Skip if this is a force delete
        if (method_exists($patient, 'isForceDeleting') && $patient->isForceDeleting()) {
            return;
        }

        // CASCADE: Soft-delete child records. Their observers will enqueue
        // delete sync operations automatically (PatientFileObserver, PatientNoteObserver).
        // This ensures the remote server also receives delete operations for child records.
        try {
            $patient->loadMissing(['files', 'notes', 'visits']);
            
            $fileCount = $patient->files->count();
            foreach ($patient->files as $file) {
                $file->delete(); // Triggers PatientFileObserver::deleted()
            }
            
            $noteCount = $patient->notes->count();
            foreach ($patient->notes as $note) {
                $note->delete(); // Triggers PatientNoteObserver::deleted()
            }
            
            $visitCount = $patient->visits->count();
            foreach ($patient->visits as $visit) {
                $visit->delete(); // Triggers PatientVisitObserver (if exists)
            }
            
            Log::info("[PatientObserver] Cascade deleted {$fileCount} files, {$noteCount} notes, {$visitCount} visits for patient: " . $patient->uuid);
        } catch (\Throwable $e) {
            Log::warning('[PatientObserver] Cascade delete failed for patient ' . $patient->uuid . ': ' . $e->getMessage());
        }

        // Dedup check
        if ($this->hasExistingSyncQueueItem($patient->uuid, 'delete')) {
            return;
        }

        try {
            $syncQueue = app(SyncQueueService::class);
            $syncQueue->enqueueOperation(
                entity: 'Patient',
                operation: 'delete',
                recordUuid: $patient->uuid,
                payload: null,
            );
            Log::info('[PatientObserver] Enqueued sync for deleted patient: ' . $patient->uuid);
        } catch (\Throwable $e) {
            Log::warning('[PatientObserver] Failed to enqueue patient delete sync: ' . $e->getMessage());
        }
    }

    /**
     * Handle the Patient "restored" event (SoftDeletes).
     * Uses 'update' operation (same as PatientFileObserver) since FullSyncService
     * does not have a dedicated 'restore' handler — it falls through to 'default' and
     * logs a warning. Treating restore as update ensures the remote record is re-activated.
     */
    public function restored(Patient $patient): void
    {
        try {
            $syncQueue = app(SyncQueueService::class);
            $syncQueue->enqueueOperation(
                entity: 'Patient',
                operation: 'update',
                recordUuid: $patient->uuid,
                payload: [
                    'restored_at' => now()->toIso8601String(),
                ],
            );
            Log::info('[PatientObserver] Enqueued sync for restored patient: ' . $patient->uuid);
        } catch (\Throwable $e) {
            Log::warning('[PatientObserver] Failed to enqueue patient restore sync: ' . $e->getMessage());
        }
    }
}
