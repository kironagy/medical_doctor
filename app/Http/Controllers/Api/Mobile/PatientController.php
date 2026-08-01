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
     *      already exists, we return the existing patient (HTTP 200).
     *   2. If no patient with that UUID exists, we create one (HTTP 201).
     *   3. If no 'uuid' is provided, the model generates one automatically.
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
            'primary_doctor_id' => 'nullable|integer',
            'created_by_id' => 'nullable|integer',
        ]);

        // Ensure UUID is populated so it is never undefined and is ready for sync
        if (empty($validated['uuid'])) {
            $validated['uuid'] = (string) \Illuminate\Support\Str::uuid();
        }

        // ── IDEMPOTENCY CHECK ──────────────────────────────────────────
        if (!empty($validated['uuid'])) {
            $existing = Patient::where('uuid', $validated['uuid'])->first();
            if ($existing) {
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

        // Use the currently authenticated user (session-based or Sanctum)
        $user = $request->user();

        if ($user) {
            $validated['primary_doctor_id'] = $user->id;
            $validated['created_by_id'] = $user->id;
        } elseif (config('database.default') === 'sqlite') {
            // SQLite (embedded NativePHP mobile app) is single-user and auth middleware is bypassed.
            // Fall back to the first available user/doctor record so the database constraint does not fail.
            $fallbackUser = \App\Domains\Users\Models\User::first();
            if ($fallbackUser) {
                $validated['primary_doctor_id'] = $fallbackUser->id;
                $validated['created_by_id'] = $fallbackUser->id;
                \Illuminate\Support\Facades\Log::info('[MobilePatient] SQLite fallback: assigned primary_doctor_id from first user', [
                    'doctor_id' => $fallbackUser->id
                ]);
            } else {
                \Illuminate\Support\Facades\Log::warning('[MobilePatient] SQLite fallback failed: No users found in database');
            }
        } elseif (!empty($validated['primary_doctor_id'])) {
            $validated['created_by_id'] ??= $validated['primary_doctor_id'];
        } else {
            \Illuminate\Support\Facades\Log::warning('[MobilePatient] Creating patient WITHOUT primary_doctor_id', [
                'uuid' => $validated['uuid'] ?? 'not_set',
                'name' => $validated['name'] ?? 'unknown',
            ]);
        }

        try {
            \Illuminate\Support\Facades\Log::info('[INSTRUMENT] PatientController::store() - creating patient', [
                'formData_uuid' => $validated['uuid'] ?? 'NOT_PROVIDED',
                'name' => $validated['name'] ?? 'unknown',
                'is_nativephp' => config('database.default') === 'sqlite' ? 'YES' : 'NO',
            ]);

            // ═══════════════════════════════════════════════════════════════
            //  CAPTURE BEARER TOKEN FROM FRONTEND
            // ═══════════════════════════════════════════════════════════════
            // The frontend (addPatient() in useWorkspace.js) sends the
            // production API token in the Authorization header:
            //   Authorization: Bearer {token}
            //
            // On the EMBEDDED LARAVEL (SQLite), this token is NOT used for
            // authentication (auth:sanctum middleware is disabled). Instead,
            // we capture it here and store it in ApiService so that the sync
            // engine can use it when making authenticated requests to the
            // PRODUCTION server.
            //
            // Without this, the sync engine calls ApiService::getToken()
            // which returns null, resulting in a 401 from the production
            // server, and the patient NEVER gets created on production.
            if (config('database.default') === 'sqlite') {
                $bearerToken = $request->bearerToken();
                if ($bearerToken) {
                    try {
                        app(\App\Services\Mobile\ApiService::class)->setToken($bearerToken);
                        \Illuminate\Support\Facades\Log::info('[MobilePatient] Bearer token captured and stored in ApiService');
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('[MobilePatient] Failed to capture Bearer token: ' . $e->getMessage());
                    }
                } else {
                    \Illuminate\Support\Facades\Log::warning('[MobilePatient] No Bearer token in request — sync will fail with 401');
                }
            }

            $patient = Patient::create($validated);

            \Illuminate\Support\Facades\Log::info('[INSTRUMENT] PatientController::store() - after create', [
                'patient_id' => $patient->id,
                'patient_uuid' => $patient->uuid,
                'sync_status_before' => $patient->sync_status,
                'name' => $patient->name,
            ]);

            // ── Mark as pending_create on Embedded Laravel ──────────────
            // When running on NativePHP (embedded Laravel), this patient is
            // created in local SQLite ONLY — the production API doesn't know
            // about it yet. Setting sync_status to 'pending_create' ensures:
            //   1. The sync engine (SyncEngineService) picks it up and
            //      pushes it to the production server.
            //   2. The paginated() merge logic in PatientRepository correctly
            //      adds it back when the production API returns a list
            //      without it.
            //   3. The /_native/api/patients/pending endpoint returns it
            //      for frontend rehydration after app restart.
            // Without this, the patient would NOT appear in the refresh
            // response and would disappear from the UI about 1 second
            // after creation.
            if (config('database.default') === 'sqlite') {
                $patient->update(['sync_status' => 'pending_create']);
                \Illuminate\Support\Facades\Log::info('[INSTRUMENT] PatientController::store() - sync_status set to pending_create', [
                    'patient_uuid' => $patient->uuid,
                    'sync_status_after' => $patient->fresh()->sync_status,
                ]);
            }

            $this->logger->log('patient_created', 'Patient', $patient->uuid, [
                'patient_name' => $patient->name,
            ]);

            return response()->json(new PatientResource($patient->fresh()), 201);
        } catch (\Illuminate\Database\QueryException $e) {
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

        // ═══ SYNC-004 FIX: Mark patient as pending_update on SQLite ═══════
        // On the embedded Laravel (SQLite), updates must be marked as
        // pending_update so the sync engine uploads them to the production
        // server. Without this, the update stays local and the server
        // overwrites it on the next sync cycle (data loss).
        if (config('database.default') === 'sqlite') {
            $patient->update(array_merge($validated, [
                'sync_status' => 'pending_update',
                'client_updated_at' => now(),
            ]));
        } else {
            $patient->update($validated);
        }

        $this->logger->log('patient_updated', 'Patient', $patient->uuid, [
            'patient_name' => $patient->name,
        ]);

        return response()->json(new PatientResource($patient->fresh()));
    }

    public function destroy(string $uuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('delete', $patient);

        // ═══ SYNC-002 FIX: Mark as pending_delete on SQLite ═══════════════
        // On the embedded Laravel (SQLite), we must NOT soft-delete the
        // patient immediately. Instead, we mark it as pending_delete so
        // the sync engine can:
        //   1. Upload the delete to the production server
        //   2. THEN force-delete the local record
        //
        // Previously, calling $patient->delete() here set deleted_at but
        // left sync_status unchanged. The sync engine never picked it up
        // because it queries by sync_status='pending_delete'.
        //
        // On the production MySQL (non-SQLite), we use soft-delete as
        // before because the website UI uses trashed() queries.
        if (config('database.default') === 'sqlite') {
            $patient->update([
                'sync_status' => 'pending_delete',
                'client_updated_at' => now(),
            ]);
        } else {
            $patient->delete();
        }

        $this->logger->log('patient_deleted', 'Patient', $patient->uuid, [
            'patient_name' => $patient->name,
        ]);

        return response()->json(['message' => 'Patient deleted successfully']);
    }
}
