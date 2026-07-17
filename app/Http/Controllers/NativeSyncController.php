<?php

namespace App\Http\Controllers;

use App\Models\PendingOperation;
use App\Services\FullSyncService;
use App\Repositories\Api\ApiPatientRepository;
use App\Repositories\Api\ApiPatientVisitRepository;
use App\Repositories\Api\ApiPatientNoteRepository;
use App\Repositories\Api\ApiPatientFileRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NativeSyncController extends Controller
{
    public function __construct(
        private FullSyncService $fullSync,
        private ApiPatientRepository $apiPatient,
        private ApiPatientVisitRepository $apiVisit,
        private ApiPatientNoteRepository $apiNote,
        private ApiPatientFileRepository $apiFile
    ) {}

    public function sync(Request $request)
    {
        Log::info('NativeSyncController: Starting Sync');

        try {
            // 1. Push all pending operations
            $pending = PendingOperation::oldest()->get();
            foreach ($pending as $op) {
                try {
                    if ($op->entity_type === 'Patient') {
                        if ($op->action === 'create') {
                            $this->apiPatient->create($op->payload);
                        } elseif ($op->action === 'update') {
                            $this->apiPatient->update($op->uuid, $op->payload);
                        } elseif ($op->action === 'delete') {
                            $this->apiPatient->delete($op->uuid);
                        }
                    } elseif ($op->entity_type === 'PatientVisit') {
                        if ($op->action === 'create') {
                            $patientUuid = $op->payload['patient_uuid'] ?? null;
                            if ($patientUuid) {
                                $this->apiVisit->create($patientUuid, $op->payload);
                            }
                        } elseif ($op->action === 'update') {
                            $this->apiVisit->update((int)$op->uuid, $op->payload);
                        } elseif ($op->action === 'delete') {
                            $this->apiVisit->delete((int)$op->uuid);
                        }
                    } elseif ($op->entity_type === 'PatientNote') {
                        if ($op->action === 'create') {
                            $patientUuid = $op->payload['patient_uuid'] ?? null;
                            if ($patientUuid) {
                                $this->apiNote->create($patientUuid, $op->payload);
                            }
                        } elseif ($op->action === 'update') {
                            $patientUuid = $op->payload['patient_uuid'] ?? null;
                            if ($patientUuid) {
                                $this->apiNote->update($patientUuid, $op->uuid, $op->payload);
                            }
                        } elseif ($op->action === 'delete') {
                            $patientUuid = $op->payload['patient_uuid'] ?? null;
                            if ($patientUuid) {
                                $this->apiNote->delete($patientUuid, $op->uuid);
                            }
                        }
                    }
                    // Delete the operation once successfully pushed
                    $op->delete();
                } catch (\Exception $e) {
                    Log::error("Failed to push operation {$op->id} ({$op->entity_type} {$op->action}): " . $e->getMessage());
                    // Keep the operation in queue so it can be retried next time
                }
            }

            // 2. Pull all remote data
            $this->fullSync->syncAll();

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('NativeSyncController error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
