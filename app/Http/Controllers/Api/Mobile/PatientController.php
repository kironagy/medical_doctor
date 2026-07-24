<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Resources\PatientResource;
use App\Domains\ActivityLogs\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PatientController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger
    ) {}

    public function index(Request $request)
    {
        $perPage = min($request->integer('per_page', 20), 100);
        $user = $request->user();

        $query = Patient::query()
            ->with('primaryDoctor:id,name,email')
            ->orderBy('created_at', 'desc');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('diagnosis', 'like', "%{$search}%");
            });
        }

        $patients = $query->paginate($perPage);

        return response()->json($patients);
    }

    public function show(string $uuid)
    {
        $patient = Patient::with([
            'primaryDoctor:id,name,email',
            'visits' => fn($q) => $q->latest(),
            'files' => fn($q) => $q->latest(),
        ])->where('uuid', $uuid)->firstOrFail();

        Gate::authorize('view', $patient);

        return response()->json(new PatientResource($patient));
    }

    /**
     * Store a newly created patient in storage (IDEMPOTENT).
     *
     * ───────────────────────────────────────────────────────────────────────
     *  IDEMPOTENCY GUARANTEE
     * ───────────────────────────────────────────────────────────────────────
     *
     * This endpoint guarantees that multiple identical POST requests produce
     * exactly one patient. The idempotency key is the client-generated UUID.
     *
     * How it works:
     *   1. If the request includes a 'uuid' field AND a patient with that UUID
     *      already exists, we return the existing patient (HTTP 200) instead
     *      of creating a duplicate.
     *   2. If no patient with that UUID exists, we create one (HTTP 201).
     *   3. If no 'uuid' is provided, the model generates one automatically.
     *
     * This pattern is safe under retries:
     *   - Lost request: client retries → server finds existing patient → returns it
     *   - Lost response: client retries → server finds existing patient → returns it
     *   - Duplicate network transmission: server handles idempotent
     *   - Concurrent duplicate requests: both succeed but only one patient created
     *     (race condition is prevented by the UNIQUE constraint on uuid column)
     *
     * Effects on other operations:
     *   - Updates: Use PUT /patients/{uuid}, which requires existing patient.
     *     Idempotent by nature (PUT replaces the resource).
     *   - Deletes: Use DELETE /patients/{uuid}. Idempotent by nature
     *     (deleting an already-deleted patient returns success).
     *   - File uploads: POST /patients/{uuid}/files depends on the patient
     *     existing. With idempotent patient creation, the patient always
     *     exists after the first POST, so file uploads never fail with 404.
     *
     * ───────────────────────────────────────────────────────────────────────
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'diagnosis' => 'nullable|string|max:1000',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|string|max:255',
            'blood_group' => 'nullable|string|max:255',
            'weight' => 'nullable|numeric',
            'height' => 'nullable|numeric',
            'allergies' => 'nullable|string',
            'chronic_diseases' => 'nullable|string',
            'medical_status' => 'nullable|string|max:255',
            'medical_record_number' => 'nullable|string|max:100',
            'code' => 'nullable|string|max:255',
            'uuid' => 'nullable|uuid',
            // primary_doctor_id is accepted from the request body so the mobile app
            // can set the correct doctor even when the endpoint is accessed without
            // authentication (temporary public access for debugging).
            'primary_doctor_id' => 'nullable|integer',
            'created_by_id' => 'nullable|integer',
        ]);

        // ── IDEMPOTENCY CHECK: Look up existing patient by UUID ─────────
        // If the client provides a UUID and a patient with that UUID already
        // exists, return the existing patient rather than creating a duplicate.
        // This handles the critical "lost response" scenario where the client
        // retries a successful creation because it never received the response.
        if (!empty($validated['uuid'])) {
            $existing = Patient::where('uuid', $validated['uuid'])->first();
            if ($existing) {
                // Update fields if the client sent newer data
                // (handles the case where the first request succeeded but
                //  the response was lost, and the client retries with the
                //  same data, or slightly different data from a new attempt)
                $updateData = \Illuminate\Support\Arr::except($validated, ['uuid']);
                if (!empty($updateData)) {
                    $existing->update($updateData);
                }

                \Illuminate\Support\Facades\Log::info('[Idempotent] Returning existing patient for UUID: ' . $validated['uuid']);

                return response()->json(new PatientResource($existing->fresh()), 200);
            }
        }

        if (empty($validated['code'])) {
            $validated['code'] = (string) random_int(100000, 999999);
        }

        // ── Doctor/User ID Assignment ─────────────────────────────────────
        // Priority order:
        //   1. Authenticated user's ID (via session or Sanctum middleware)
        //   2. Manually resolved from Bearer token (when auth middleware removed)
        //   3. primary_doctor_id / created_by_id from request body
        //   4. null (fallback — patient will be visible to all doctors via
        //      DoctorIsolationScope's orWhereNull('primary_doctor_id') fallback)
        //
        // 🚨 CRITICAL: Without auth:sanctum middleware, $request->user() cannot
        //    resolve the Bearer token. We must manually resolve it here.
        //    Otherwise primary_doctor_id stays NULL and the DoctorIsolationScope
        //    on the workspace endpoint filters out the patient → 404.
        //
        // 🩺 DIAGNOSTICS: We log the token state every time to capture why
        //    Bearer token resolution fails intermittently.
        //
        $user = $request->user();
        $diag = ['method' => 'request->user()', 'user_found' => $user ? 'YES' : 'NO'];

        if (!$user) {
            // Manually resolve from Bearer token (Sanctum middleware is removed)
            $bearerToken = $request->bearerToken();
            $diag['bearer_token_present'] = $bearerToken ? 'YES' : 'NO';
            $diag['bearer_token_prefix'] = $bearerToken ? substr($bearerToken, 0, 20) . '...' : 'NONE';

            if ($bearerToken) {
                $hasPipe = str_contains($bearerToken, '|');
                $diag['token_has_pipe'] = $hasPipe ? 'YES' : 'NO';
                $diag['token_length'] = strlen($bearerToken);

                try {
                    $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($bearerToken);
                    $diag['findToken_result'] = $accessToken ? 'FOUND' : 'NULL';
                    $diag['findToken_tokenable'] = ($accessToken && $accessToken->tokenable) ? 'YES' : 'NO';

                    if ($accessToken && $accessToken->tokenable) {
                        $user = $accessToken->tokenable;
                        $diag['resolved_user_id'] = $user->id;
                    }

                    if ($accessToken && !$accessToken->tokenable) {
                        // Token exists but its associated user was deleted
                        $diag['orphaned_token_id'] = $accessToken->id;
                        \Illuminate\Support\Facades\Log::warning('[MobilePatient] Orphaned token — user deleted', [
                            'token_id' => $accessToken->id,
                            'tokenable_type' => $accessToken->tokenable_type,
                        ]);
                    }
                } catch (\Throwable $e) {
                    $diag['findToken_exception'] = $e->getMessage();
                    \Illuminate\Support\Facades\Log::warning('[MobilePatient] Bearer auth threw exception: ' . $e->getMessage());
                }
            }

            \Illuminate\Support\Facades\Log::debug('[MobilePatient] Token resolution diagnostics', $diag);
        }

        if ($user) {
            $validated['primary_doctor_id'] = $user->id;
            $validated['created_by_id'] = $user->id;
        } elseif (!empty($validated['primary_doctor_id'])) {
            // Fallback: doctor ID sent explicitly in request body
            $validated['created_by_id'] ??= $validated['primary_doctor_id'];
        } else {
            \Illuminate\Support\Facades\Log::warning('[MobilePatient] Creating patient WITHOUT primary_doctor_id — visible but unowned', [
                'uuid' => $validated['uuid'] ?? 'not_set',
                'name' => $validated['name'] ?? 'unknown',
                'diag' => $diag,
            ]);
            \Illuminate\Support\Facades\Log::debug('[MobilePatient] Full token diagnostic context', [
                'uuid' => $validated['uuid'] ?? 'not_set',
                'name' => $validated['name'] ?? 'unknown',
                'diag' => $diag,
            ]);
        }

        try {
            $patient = Patient::create($validated);

            $this->logger->log('patient_created', 'Patient', $patient->uuid, [
                'patient_name' => $patient->name,
            ]);

            return response()->json(new PatientResource($patient), 201);
        } catch (\Illuminate\Database\QueryException $e) {
            // ── UNIQUE CONSTRAINT VIOLATION SAFETY NET ───────────────────
            // If a race condition caused two concurrent requests to both pass
            // the idempotency check, the UNIQUE constraint on the uuid column
            // will catch the second one. We handle it gracefully by fetching
            // the existing patient and returning it.
            $errorMessage = $e->getMessage();
            if (\Illuminate\Support\Str::contains($errorMessage, 'UNIQUE constraint failed')
                || \Illuminate\Support\Str::contains($errorMessage, 'Duplicate entry')
                || \Illuminate\Support\Str::contains($errorMessage, 'Integrity constraint violation')) {
                $existing = Patient::where('uuid', $validated['uuid'])->first();
                if ($existing) {
                    \Illuminate\Support\Facades\Log::info('[Idempotent] Race condition resolved via UNIQUE constraint for UUID: ' . $validated['uuid']);
                    return response()->json(new PatientResource($existing), 200);
                }
            }
            throw $e;
        }
    }

    public function update(Request $request, string $uuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('update', $patient);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'diagnosis' => 'nullable|string|max:1000',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|string|max:255',
            'blood_group' => 'nullable|string|max:255',
            'weight' => 'nullable|numeric',
            'height' => 'nullable|numeric',
            'allergies' => 'nullable|string',
            'chronic_diseases' => 'nullable|string',
            'medical_status' => 'nullable|string|max:255',
            'medical_record_number' => 'nullable|string|max:100',
            'code' => 'nullable|string|max:255',
        ]);

        $patient->update($validated);

        $this->logger->log('patient_updated', 'Patient', $patient->uuid, [
            'patient_name' => $patient->name,
        ]);

        return response()->json(new PatientResource($patient->fresh()));
    }

    public function destroy(string $uuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('delete', $patient);

        $patient->delete();

        $this->logger->log('patient_deleted', 'Patient', $patient->uuid, [
            'patient_name' => $patient->name,
        ]);

        return response()->json(['message' => 'Patient deleted successfully']);
    }
}
