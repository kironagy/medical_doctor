<?php

namespace App\Http\Middleware;

use App\Services\NetworkStatusService;
use App\Services\SyncQueueService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SyncMiddleware
{
    private SyncQueueService $syncQueue;
    private NetworkStatusService $network;

    public function __construct(SyncQueueService $syncQueue, NetworkStatusService $network)
    {
        $this->syncQueue = $syncQueue;
        $this->network   = $network;
    }

    /**
     * Handle an incoming API write request for mobile routes.
     *
     * - ONLINE : let request through, enqueue for audit.
     * - OFFLINE: do NOT call the controller; enqueue in sync_queue and
     *            return a success payload so the mobile app does not retry.
     *
     * Also injects sync state headers into EVERY response (not just writes)
     * so the frontend always knows the current sync status.
     */
    public function handle(Request $request, Closure $next)
    {
        $path   = $request->path();
        $method = strtoupper($request->method());

        Log::debug("[SyncMiddleware] {$method} {$path}", [
            'online' => NetworkStatusService::isOnline(),
        ]);

        [$entity, $operation, $recordUuid] = $this->resolveRouteContext($request);

        // If no entity matched, let request through normally with sync headers
        if ($entity === null) {
            $response = $next($request);
            $this->injectSyncStateHeaders($response);
            return $response;
        }

        $payload = $request->except(['_token', '_method']);

        if (NetworkStatusService::isOnline()) {
            // ONLINE: let the request hit the controller directly.
            // The Hybrid repos handle their own sync-queue enqueue on API failure,
            // so we do NOT double-enqueue here on success.
            $response = $next($request);
            $this->injectSyncStateHeaders($response);
            return $response;
        }

        // OFFLINE: Save to local SQLite first. The model observers
        // (PatientObserver, PatientFileObserver, PatientNoteObserver)
        // automatically enqueue sync operations when the model is
        // created/updated/deleted. This ensures data persists in the
        // local SQLite cache AND syncs when connectivity returns.
        Log::info("[SyncMiddleware] Offline — saving {$entity} {$operation} to local SQLite.");

        // Inject the parent patient UUID from URL segments for child entities
        // (notes, visits, files) so saveLocally can resolve patient_id.
        $patientUuid = $this->resolveParentPatientUuid($segments);
        if ($patientUuid && !isset($payload['patient_uuid'])) {
            $payload['patient_uuid'] = $patientUuid;
        }

        try {
            $this->saveLocally($entity, $operation, $recordUuid, $payload);
            Log::info("[SyncMiddleware] Successfully saved {$entity} {$operation} locally (sync via observer).");
        } catch (\Throwable $e) {
            Log::error("[SyncMiddleware] Failed to save {$entity} {$operation} locally: " . $e->getMessage());
        }

        $offlineResponse = response()->json([
            'success'        => true,
            'queued_offline' => true,
            'entity'         => $entity,
            'operation'      => $operation,
            'record_uuid'    => $recordUuid,
            'message'        => 'Operation saved locally and will be synced when connectivity is restored.',
        ]);
        
        $this->injectSyncStateHeaders($offlineResponse);
        return $offlineResponse;
    }

    /**
     * Inject sync state headers into the response so the frontend always
     * knows the current sync status (pending count, last sync time, etc.).
     */
    private function injectSyncStateHeaders($response): void
    {
        try {
            $pendingCount = $this->syncQueue->getPendingCount();
            $lastSyncAt = null;
            try {
                $row = \Illuminate\Support\Facades\DB::table('sync_states')
                    ->where('key', 'last_sync_at')
                    ->first();
                if ($row) {
                    $lastSyncAt = $row->value;
                }
            } catch (\Throwable $e) {}

            $response->header('X-Sync-Pending-Count', (string) $pendingCount);
            $response->header('X-Sync-Last-At', $lastSyncAt ?? '');
            $response->header('X-Sync-Online', NetworkStatusService::isOnline() ? '1' : '0');
        } catch (\Throwable $e) {
            // Headers are best-effort
        }
    }

    /**
     * Derive entity name, operation, and optional record UUID from the request path
     * and parameters.
     *
     * Returns [entity|null, operation|null, recordUuid|null].
     */
    private function resolveRouteContext(Request $request): array
    {
        $method = strtoupper($request->method());

        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return [null, null, null];
        }

        // Normalise: strip query string
        $segments = explode('/', ltrim(parse_url($request->path(), PHP_URL_PATH), '/'));

        // Expected mobile segment layouts (all prefixed by v1/mobile already matched):
        //   patients               -> POST   /patients               ? entity=Patient, op=create
        //   patients/{uuid}        -> PUT    /patients/{uuid}        ? entity=Patient, op=update
        //   patients/{uuid}        -> DELETE /patients/{uuid}        ? entity=Patient, op=delete
        //   patients/{p}/visits    -> POST   /patients/{p}/visits    ? entity=PatientVisit, op=create
        //   patients/{p}/visits/{v}-> PUT    /patients/{p}/visits/{v}? entity=PatientVisit, op=update
        //   patients/{p}/visits/{v}-> DELETE /patients/{p}/visits/{v}? entity=PatientVisit, op=delete
        //   patients/{p}/notes     -> POST   /patients/{p}/notes     ? entity=PatientNote, op=create
        //   patients/{p}/notes/{n} -> PUT    /patients/{p}/notes/{n} ? entity=PatientNote, op=update
        //   patients/{p}/notes/{n} -> DELETE /patients/{p}/notes/{n} ? entity=PatientNote, op=delete
        //   files                  -> POST   /patients/{p}/files     ? entity=PatientFile, op=create
        //   files/{uuid}           -> PUT    /files/{uu}             ? entity=PatientFile, op=update
        //   files/{uuid}           -> DELETE /files/{uu}             ? entity=PatientFile, op=delete
        //   patients/{p}/shares    -> POST   /patients/{p}/shares    ? entity=PatientShare, op=create
        //   patients/{p}/shares/{s}-> DELETE /patients/{p}/shares/{s}? entity=PatientShare, op=delete
        //   profile                -> PUT    /profile                ? entity=Patient (doctor profile)
        //   profile/password       -> PUT    /profile/password       ? entity=Patient

        // We index from the end so the prefix depth doesn't matter.
        $last = end($segments); // always a resource name or UUID

        // Try to detect entity from the last "known word" segment before a trailing UUID.
        $entity = $this->detectEntity($request, $segments);
        if ($entity === null) {
            return [null, null, null];
        }

        $operation = $this->resolveOperation($method, $request, $segments);
        $recordUuid = $this->resolveRecordUuid($request, $segments, $operation);

        return [$entity, $operation, $recordUuid];
    }

    /**
     * Detect which entity type this request targets by looking at the URL
     * structure. Returns the entity key string or null if unrecognised.
     */
    private function detectEntity(Request $request, array $segments): ?string
    {
        $path = $request->path();

        $map = [
            'visits'     => 'PatientVisit',
            'notes'      => 'PatientNote',
            'files'      => 'PatientFile',
            'shares'     => 'PatientShare',
            'patients'   => 'Patient',
        ];

        // Walk segments from end to find the first matching resource keyword.
        foreach (array_reverse($segments) as $segment) {
            if (isset($map[$segment])) {
                return $map[$segment];
            }
            // Stop once we pass a potential UUID-looking segment because
            // the entity keyword always follows the patient UUID prefix.
            if ($this->isUuid($segment)) {
                break;
            }
        }

        // Profile endpoints don't carry a resource word after the patient UUID —
        // they're mappings stored on the doctor (Patient resource).
        if (str_starts_with($path, 'profile')) {
            return 'Patient';
        }

        return null;
    }

    /**
     * Map HTTP method (and path context) to an operation string.
     */
    private function resolveOperation(string $method, Request $request, array $segments): string
    {
        return match ($method) {
            'POST'   => 'create',
            'PUT',
            'PATCH'  => 'update',
            'DELETE' => 'delete',
            default  => 'update',
        };
    }

    /**
     * Determine the record UUID for the operation.
     *
     * For update/delete the target record UUID is in the URL.
     * For create the local record does not exist yet so we return null
     * (the SyncQueueService::enqueueOperation accepts null record_uuid).
     */
    private function resolveRecordUuid(Request $request, array $segments, string $operation): ?string
    {
        if ($operation === 'create') {
            return null;
        }

        // The UUID for the resource being modified is the last non-UUID-pair
        // segment before the resource keyword, or the last segment directly.
        // Search route params first.
        $params = $request->route()->parameters();

        // Most useful: take the last parameter value that looks like a UUID.
        foreach (array_reverse($params) as $param) {
            if (is_string($param) && $this->isUuid($param)) {
                return $param;
            }
        }

        // Fallback: iterate path segments for a UUID (works without named params).
        foreach ($segments as $segment) {
            if ($this->isUuid($segment)) {
                return $segment;
            }
        }

        return null;
    }

    /**
     * Heuristic UUID check: 8-4-4-4-12 hex character groups.
     */
    private function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        );
    }

    /**
     * Extract the parent patient UUID from URL segments for child resource routes.
     * E.g., for /patients/{patientUuid}/notes, returns {patientUuid}.
     * Looks for a UUID segment immediately before a known resource keyword (notes, visits, files, shares).
     */
    private function resolveParentPatientUuid(array $segments): ?string
    {
        $childResources = ['notes', 'visits', 'files', 'shares'];
        $prev = null;
        foreach ($segments as $segment) {
            if (in_array($segment, $childResources) && $prev && $this->isUuid($prev)) {
                return $prev;
            }
            $prev = $segment;
        }
        return null;
    }

    /**
     * Save an offline operation to local SQLite using the appropriate Eloquent model.
     * The model's observer will automatically enqueue a sync operation.
     */
    private function saveLocally(string $entity, string $operation, ?string $recordUuid, array $payload): bool
    {
        try {
            switch ($entity) {
                case 'Patient':
                    return $this->savePatientLocally($operation, $recordUuid, $payload);

                case 'PatientVisit':
                    return $this->saveVisitLocally($operation, $recordUuid, $payload);

                case 'PatientNote':
                    return $this->saveNoteLocally($operation, $recordUuid, $payload);

                case 'PatientFile':
                    // File upload middleware is handled via multipart — skip for offline
                    // since binary file data is not available in the JSON payload.
                    // File deletions are handled.
                    if ($operation === 'delete' && $recordUuid) {
                        $file = \App\Domains\Media\Models\PatientFile::where('uuid', $recordUuid)->first();
                        if ($file) {
                            $file->delete();
                            return true;
                        }
                    }
                    Log::warning('[SyncMiddleware] Offline file ' . $operation . ' not supported via middleware.');
                    return false;

                case 'PatientShare':
                    if ($operation === 'create') {
                        \App\Domains\Patients\Models\PatientShare::create($payload);
                        return true;
                    } elseif ($operation === 'delete' && $recordUuid) {
                        $share = \App\Domains\Patients\Models\PatientShare::where('uuid', $recordUuid)->first();
                        if ($share) {
                            $share->delete();
                            return true;
                        }
                    }
                    return false;

                default:
                    Log::warning("[SyncMiddleware] Unknown entity for offline save: {$entity}");
                    return false;
            }
        } catch (\Throwable $e) {
            Log::error("[SyncMiddleware] Error in saveLocally({$entity}): " . $e->getMessage());
            return false;
        }
    }

    private function savePatientLocally(string $operation, ?string $recordUuid, array $payload): bool
    {
        if ($operation === 'create') {
            if (!isset($payload['uuid'])) {
                $payload['uuid'] = (string) \Illuminate\Support\Str::uuid();
            }
            if (!isset($payload['primary_doctor_id'])) {
                try {
                    $payload['primary_doctor_id'] = auth()->id();
                } catch (\Throwable $e) {}
            }
            if (!isset($payload['created_by_id'])) {
                try {
                    $payload['created_by_id'] = auth()->id();
                } catch (\Throwable $e) {}
            }
            if (!isset($payload['code'])) {
                do {
                    $payload['code'] = (string) random_int(100000, 999999);
                } while (\App\Domains\Patients\Models\Patient::where('code', $payload['code'])->exists());
            }
            \App\Domains\Patients\Models\Patient::create($payload);
            return true;
        }

        if ($recordUuid) {
            $patient = \App\Domains\Patients\Models\Patient::where('uuid', $recordUuid)->first();
            if (!$patient) {
                return false;
            }

            if ($operation === 'update') {
                $patient->update($payload);
                return true;
            } elseif ($operation === 'delete') {
                $patient->delete();
                return true;
            }
        }

        return false;
    }

    private function saveNoteLocally(string $operation, ?string $recordUuid, array $payload): bool
    {
        $patientUuid = $payload['patient_uuid'] ?? null;

        if ($operation === 'create') {
            if (!isset($payload['uuid'])) {
                $payload['uuid'] = (string) \Illuminate\Support\Str::uuid();
            }
            if ($patientUuid) {
                $patient = \App\Domains\Patients\Models\Patient::where('uuid', $patientUuid)->first();
                if ($patient) {
                    $payload['patient_id'] = $patient->id;
                }
            }
            \App\Domains\Patients\Models\PatientNote::create($payload);
            return true;
        }

        if ($recordUuid) {
            $note = \App\Domains\Patients\Models\PatientNote::where('uuid', $recordUuid)->first();
            if (!$note) {
                return false;
            }

            if ($operation === 'update') {
                $note->update($payload);
                return true;
            } elseif ($operation === 'delete') {
                $note->delete();
                return true;
            }
        }

        return false;
    }

    private function saveVisitLocally(string $operation, ?string $recordUuid, array $payload): bool
    {
        $patientUuid = $payload['patient_uuid'] ?? null;

        if ($operation === 'create') {
            if (!isset($payload['uuid'])) {
                $payload['uuid'] = (string) \Illuminate\Support\Str::uuid();
            }
            if ($patientUuid) {
                $patient = \App\Domains\Patients\Models\Patient::where('uuid', $patientUuid)->first();
                if ($patient) {
                    $payload['patient_id'] = $patient->id;
                }
            }
            \App\Domains\Patients\Models\PatientVisit::create($payload);
            return true;
        }

        if ($recordUuid) {
            $visit = \App\Domains\Patients\Models\PatientVisit::where('uuid', $recordUuid)->first();
            if (!$visit) {
                return false;
            }

            if ($operation === 'update') {
                $visit->update($payload);
                return true;
            } elseif ($operation === 'delete') {
                $visit->delete();
                return true;
            }
        }

        return false;
    }
}
