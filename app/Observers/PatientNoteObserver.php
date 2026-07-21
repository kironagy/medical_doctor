<?php

namespace App\Observers;

use App\Domains\Patients\Models\PatientNote;
use App\Services\SyncQueueService;
use Illuminate\Support\Facades\Log;

class PatientNoteObserver
{
    /**
     * Handle the PatientNote "created" event.
     * Enqueues a sync operation so the note gets pushed to the remote API
     * during the next sync cycle (FullSyncService::syncPendingOperations).
     */
    public function created(PatientNote $note): void
    {
        try {
            $syncQueue = app(SyncQueueService::class);

            $patientUuid = null;
            if ($note->patient) {
                $patientUuid = $note->patient->uuid;
            } else {
                // Fallback: load patient from relationship
                try {
                    $note->load('patient');
                    $patientUuid = $note->patient?->uuid;
                } catch (\Throwable $e) {
                    Log::warning('[PatientNoteObserver] Could not load patient for note: ' . $note->uuid);
                }
            }

            $syncQueue->enqueueOperation(
                entity: 'PatientNote',
                operation: 'create',
                recordUuid: $note->uuid,
                payload: [
                    'patient_uuid' => $patientUuid,
                    'content' => $note->content,
                    'category' => $note->category ?? 'general',
                    'author_id' => $note->author_id,
                ]
            );

            Log::info('[PatientNoteObserver] Enqueued sync for new note: ' . $note->uuid);
        } catch (\Throwable $e) {
            Log::warning('[PatientNoteObserver] Failed to enqueue note sync: ' . $e->getMessage());
        }
    }

    /**
     * Handle the PatientNote "updated" event.
     */
    public function updated(PatientNote $note): void
    {
        // Only enqueue if the content actually changed (avoid redundant sync on touch)
        if (!$note->wasChanged('content')) {
            return;
        }

        try {
            $syncQueue = app(SyncQueueService::class);

            $patientUuid = null;
            try {
                $note->loadMissing('patient');
                $patientUuid = $note->patient?->uuid;
            } catch (\Throwable $e) {
                Log::warning('[PatientNoteObserver] Could not load patient for note update: ' . $note->uuid);
            }

            $syncQueue->enqueueOperation(
                entity: 'PatientNote',
                operation: 'update',
                recordUuid: $note->uuid,
                payload: [
                    'patient_uuid' => $patientUuid,
                    'content' => $note->content,
                    'category' => $note->category ?? 'general',
                ]
            );

            Log::info('[PatientNoteObserver] Enqueued sync for updated note: ' . $note->uuid);
        } catch (\Throwable $e) {
            Log::warning('[PatientNoteObserver] Failed to enqueue note update sync: ' . $e->getMessage());
        }
    }

    /**
     * Handle the PatientNote "deleted" event.
     */
    public function deleted(PatientNote $note): void
    {
        try {
            $syncQueue = app(SyncQueueService::class);

            $patientUuid = null;
            try {
                $note->loadMissing('patient');
                $patientUuid = $note->patient?->uuid;
            } catch (\Throwable $e) {
                Log::warning('[PatientNoteObserver] Could not load patient for note delete: ' . $note->uuid);
            }

            $syncQueue->enqueueOperation(
                entity: 'PatientNote',
                operation: 'delete',
                recordUuid: $note->uuid,
                payload: [
                    'patient_uuid' => $patientUuid,
                ]
            );

            Log::info('[PatientNoteObserver] Enqueued sync for deleted note: ' . $note->uuid);
        } catch (\Throwable $e) {
            Log::warning('[PatientNoteObserver] Failed to enqueue note delete sync: ' . $e->getMessage());
        }
    }
}
