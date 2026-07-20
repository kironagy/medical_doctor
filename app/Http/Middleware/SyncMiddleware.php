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
     */
    public function handle(Request $request, Closure $next)
    {
        $path   = $request->path();
        $method = strtoupper($request->method());

        Log::debug("[SyncMiddleware] {$method} {$path}", [
            'online' => NetworkStatusService::isOnline(),
        ]);

        [$entity, $operation, $recordUuid] = $this->resolveRouteContext($request);

        if ($entity === null) {
            return $next($request);
        }

        $payload = $request->except(['_token', '_method']);

        if (NetworkStatusService::isOnline()) {
            // ONLINE: let the request hit the controller directly.
            // The Hybrid repos handle their own sync-queue enqueue on API failure,
            // so we do NOT double-enqueue here on success.
            return $next($request);
        }

        // OFFLINE: skip controller execution, store in queue, return success.
        Log::info("[SyncMiddleware] Offline — queuing {$entity} {$operation} instead of processing.");

        $this->syncQueue->enqueueOperation(
            $entity,
            $operation,
            $recordUuid,
            $payload
        );

        return response()->json([
            'success'        => true,
            'queued_offline' => true,
            'entity'         => $entity,
            'operation'      => $operation,
            'record_uuid'    => $recordUuid,
            'message'        => 'Operation queued and will be synced when connectivity is restored.',
        ]);
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
}
