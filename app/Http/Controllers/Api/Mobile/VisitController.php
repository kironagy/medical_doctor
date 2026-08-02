<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientVisit;
use App\Domains\ActivityLogs\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * VisitController — Offline-First CRUD for patient visits.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * OFFLINE ARCHITECTURE
 * ─────────────────────────────────────────────────────────────────────────
 *
 * On the embedded Laravel (SQLite / NativePHP), all operations are local:
 *   1. Create  → sync_status = 'pending_create'
 *   2. Update  → sync_status = 'pending_update'
 *   3. Delete  → sync_status = 'pending_delete' (no hard-delete yet)
 *
 * The SyncEngineService picks up these records on the next sync cycle and
 * uploads them to the production server.
 *
 * On the production MySQL server, operations are applied directly (no queue).
 *
 * KEY DIFFERENCES from the broken original:
 *   - Gate::authorize() is skipped when no authenticated user (SQLite)
 *   - resolvePatient() always sets primary_doctor_id (no NOT NULL errors)
 *   - Bearer token is captured for the sync engine on every write
 *   - sync_status is set correctly on every mutation
 * ─────────────────────────────────────────────────────────────────────────
 */
class VisitController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger
    ) {}

    public function index(Request $request, string $uuid)
    {
        $patient = $this->resolvePatient($uuid);

        // ── SQLite guard: Skip Gate when no authenticated user ──────────
        // On the embedded Laravel (SQLite), API routes have NO auth middleware,
        // so $request->user() is null. Gate::authorize() with null user ALWAYS
        // throws 403. We skip the check locally — the device is single-user and
        // doesn't need fine-grained authorization for its own SQLite data.
        if ($request->user()) {
            Gate::authorize('view', $patient);
        }

        $visits = $patient->visits()
            ->latest()
            ->paginate(50);

        return response()->json($visits);
    }

    public function store(Request $request, string $uuid)
    {
        $patient = $this->resolvePatient($uuid);

        // ── SQLite guard: Skip Gate when no authenticated user ──────────
        if ($request->user()) {
            Gate::authorize('update', $patient);
        }

        // ═══════════════════════════════════════════════════════════════
        //  CAPTURE BEARER TOKEN FROM FRONTEND
        // ═══════════════════════════════════════════════════════════════
        // Same pattern as PatientController::store() and NoteController::store().
        // The frontend sends the production API token so the sync engine can
        // use it when uploading this visit to the production server.
        if (config('database.default') === 'sqlite') {
            $bearerToken = $request->bearerToken();
            if ($bearerToken) {
                try {
                    app(\App\Services\Mobile\ApiService::class)->setToken($bearerToken);
                } catch (\Throwable $e) {
                    Log::warning('[MobileVisit] Failed to capture Bearer token: ' . $e->getMessage());
                }
            } else {
                Log::warning('[MobileVisit] No Bearer token in store request — sync will fail with 401');
            }
        }

        $validated = $request->validate([
            'visit_type'        => 'nullable|string|max:255',
            'visit_type_custom' => 'nullable|string|max:255',
            'reason'            => 'nullable|string|max:1000',
            'reason_custom'     => 'nullable|string|max:255',
            'visit_date'        => 'nullable|date',
            'visit_time'        => 'nullable|string|max:255',
            'session_details'   => 'nullable|array',
            'diagnosis'         => 'nullable|string|max:1000',
            'prescription'      => 'nullable|string|max:1000',
            'next_visit_date'   => 'nullable|date',
            'cost'              => 'nullable|numeric|min:0',
        ]);

        $validated['patient_id'] = $patient->id;

        // ── Mark as pending_create on Embedded Laravel ──────────────────
        // On SQLite, set sync_status so SyncEngineService picks it up.
        // On MySQL (production), set synced so it's immediately available.
        $isLocalSqlite = config('database.default') === 'sqlite';
        $validated['sync_status'] = $isLocalSqlite ? 'pending_create' : 'synced';

        // Provide a default visit_type if missing (prevents any NOT NULL issues)
        if (empty($validated['visit_type']) && empty($validated['visit_type_custom'])) {
            $validated['visit_type'] = 'general';
        }

        $visit = PatientVisit::create($validated);

        Log::info('[MobileVisit] Visit created', [
            'uuid'        => $visit->uuid,
            'patient_uuid'=> $uuid,
            'sync_status' => $visit->sync_status,
            'is_sqlite'   => $isLocalSqlite,
        ]);

        $this->logger->log('visit_created', 'PatientVisit', $visit->uuid, [
            'patient_uuid' => $uuid,
        ]);

        return response()->json($visit, 201);
    }

    public function update(Request $request, string $uuid, string $visitId)
    {
        $patient = $this->resolvePatient($uuid);

        // ── SQLite guard: Skip Gate when no authenticated user ──────────
        if ($request->user()) {
            Gate::authorize('update', $patient);
        }

        // ── Capture Bearer token ─────────────────────────────────────────
        if (config('database.default') === 'sqlite') {
            $bearerToken = $request->bearerToken();
            if ($bearerToken) {
                try {
                    app(\App\Services\Mobile\ApiService::class)->setToken($bearerToken);
                } catch (\Throwable $e) {
                    Log::warning('[MobileVisit] Failed to capture Bearer token in update: ' . $e->getMessage());
                }
            }
        }

        $visit = PatientVisit::withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)
            ->where('uuid', $visitId)
            ->where('patient_id', $patient->id)
            ->firstOrFail();

        $validated = $request->validate([
            'visit_type'        => 'sometimes|string|max:255',
            'visit_type_custom' => 'nullable|string|max:255',
            'reason'            => 'nullable|string|max:1000',
            'reason_custom'     => 'nullable|string|max:255',
            'visit_date'        => 'nullable|date',
            'visit_time'        => 'nullable|string|max:255',
            'session_details'   => 'nullable|array',
            'diagnosis'         => 'nullable|string|max:1000',
            'prescription'      => 'nullable|string|max:1000',
            'next_visit_date'   => 'nullable|date',
            'cost'              => 'nullable|numeric|min:0',
        ]);

        // ═══ Mark as pending_update on SQLite ═══════════════════════════
        // Without this, the update stays local and the sync engine never
        // uploads it to the production server (data loss).
        if (config('database.default') === 'sqlite') {
            $visit->update(array_merge($validated, [
                'sync_status'       => 'pending_update',
                'client_updated_at' => now(),
            ]));
        } else {
            $visit->update($validated);
        }

        return response()->json($visit->fresh());
    }

    public function destroy(Request $request, string $uuid, string $visitId)
    {
        $patient = $this->resolvePatient($uuid);

        // ── SQLite guard: Skip Gate when no authenticated user ──────────
        if ($request->user()) {
            Gate::authorize('update', $patient);
        }

        // ── Capture Bearer token ─────────────────────────────────────────
        if (config('database.default') === 'sqlite') {
            $bearerToken = $request->bearerToken();
            if ($bearerToken) {
                try {
                    app(\App\Services\Mobile\ApiService::class)->setToken($bearerToken);
                } catch (\Throwable $e) {
                    Log::warning('[MobileVisit] Failed to capture Bearer token in destroy: ' . $e->getMessage());
                }
            }
        }

        $visit = PatientVisit::withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)
            ->where('uuid', $visitId)
            ->where('patient_id', $patient->id)
            ->firstOrFail();

        // ═══ Mark as pending_delete on SQLite ═══════════════════════════
        // On SQLite: Mark as pending_delete but do NOT soft-delete yet.
        // The sync engine queries PatientVisit::where('sync_status', 'pending_delete')
        // WITHOUT withTrashed(), so soft-deleted visits would be invisible.
        // After successful remote delete, the sync engine calls forceDelete().
        //
        // On MySQL: Use normal soft-delete as before.
        if (config('database.default') === 'sqlite') {
            $visit->update([
                'sync_status'       => 'pending_delete',
                'client_updated_at' => now(),
            ]);
        } else {
            $visit->delete();
        }

        return response()->json(['message' => 'Visit deleted successfully']);
    }

    /**
     * Resolve a patient by UUID, creating a stub if not found locally.
     *
     * ─────────────────────────────────────────────────────────────────────
     * WHY THIS IS NEEDED
     * ─────────────────────────────────────────────────────────────────────
     * When the app is offline and a visit is added to a patient that was
     * ALSO created offline (not yet synced), the patient exists in local
     * SQLite. But race conditions or deep-links may produce a case where
     * the patient row doesn't exist yet. Creating a stub ensures FK integrity.
     *
     * CRITICAL: The stub MUST have primary_doctor_id set, or SQLite will
     * throw a NOT NULL constraint error on Patient::create().
     * ─────────────────────────────────────────────────────────────────────
     */
    private function resolvePatient(string $uuid): Patient
    {
        $patient = Patient::withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)
            ->where('uuid', $uuid)
            ->first();

        if ($patient) {
            return $patient;
        }

        // Patient not in local SQLite — create a stub so FK constraint passes.
        // This handles the case where the patient was created on the production
        // server and hasn't been downloaded yet (e.g. first offline session).
        $doctorId = $this->resolveCurrentUserId();

        $stubData = [
            'uuid'               => $uuid,
            'sync_status'        => 'synced', // Assume server-side patient — don't re-create
            'name'               => 'Patient (' . substr($uuid, 0, 8) . ')',
            'primary_doctor_id'  => $doctorId,
            'created_by_id'      => $doctorId,
        ];

        Log::info('[MobileVisit] Creating stub patient for UUID: ' . $uuid, [
            'primary_doctor_id' => $doctorId,
        ]);

        return Patient::create($stubData);
    }

    /**
     * Resolve the current user ID for FK assignment.
     *
     * Priority:
     *   1. Authenticated user (session/Sanctum)
     *   2. First user in local SQLite (offline single-user device)
     *   3. Seed a default doctor (clean install with empty DB)
     */
    private function resolveCurrentUserId(): int
    {
        // 1. Authenticated user
        $user = auth()->user();
        if ($user) {
            return $user->id;
        }

        // 2. First user in SQLite
        $localUser = \App\Domains\Users\Models\User::first();
        if ($localUser) {
            return $localUser->id;
        }

        // 3. Seed default doctor (absolute last resort — clean install)
        \App\Domains\Users\Models\User::unguard();
        $defaultUser = \App\Domains\Users\Models\User::firstOrCreate(
            ['id' => 1],
            [
                'name'     => 'Default Doctor',
                'email'    => 'doctor@local.test',
                'password' => bcrypt('password'),
                'role'     => 'doctor',
                'uuid'     => (string) \Illuminate\Support\Str::uuid(),
                'status'   => 'active',
            ]
        );
        \App\Domains\Users\Models\User::reguard();

        Log::info('[MobileVisit] Seeded default doctor for clean install', [
            'doctor_id' => $defaultUser->id,
        ]);

        return $defaultUser->id;
    }
}
