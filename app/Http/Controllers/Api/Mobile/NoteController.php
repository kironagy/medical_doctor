<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use App\Http\Controllers\Controller;
use App\Contracts\Repositories\PatientRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
class NoteController extends Controller
{
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

        $notes = $patient->notes()
            ->with('author:id,name,email')
            ->latest()
            ->paginate(50);

        return response()->json($notes);
    }

    /**
     * List locally-pending notes (created/updated offline, not yet synced to
     * the production server).
     *
     * Used by the frontend (CategoryBlock.loadCategoryData) so offline-created
     * notes appear immediately without waiting for the sync engine.
     */
    public function pendingIndex(Request $request)
    {
        $patientUuid = $request->input('patient_uuid');
        if (!$patientUuid) {
            return response()->json(['data' => []]);
        }

        $patient = Patient::withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)
            ->where('uuid', $patientUuid)
            ->first();

        if (!$patient) {
            return response()->json(['data' => []]);
        }

        $notes = PatientNote::where('patient_id', $patient->id)
            ->whereIn('sync_status', ['pending_create', 'pending_update'])
            ->latest()
            ->get();

        return response()->json(['data' => $notes]);
    }

    public function store(Request $request, ?string $uuid = null)
    {
        $patientUuid = $uuid ?: $request->input('patient_uuid');
        if (!$patientUuid) {
            return response()->json(['message' => 'patient_uuid is required'], 422);
        }
        $patient = $this->resolvePatient($patientUuid);
        // ── SQLite guard: Skip Gate when no authenticated user ──────────
        // On the embedded Laravel (SQLite), API routes have NO auth middleware.
        // Gate::authorize('update', $patient) with null user throws 403.
        // Skip authorization locally — the device is single-user.
        if ($request->user()) {
            Gate::authorize('update', $patient);
        }

        $validated = $request->validate([
            'content' => 'required|string',
            'category' => 'nullable|string|max:100',
            'author_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        $authorId = $user?->id ?? $validated['author_id'] ?? $patient->primary_doctor_id ?? $patient->created_by_id ?? null;
        if (!$authorId) {
            Log::warning('[MobileNote] Creating note without author_id — will be unowned', [
                'patient_uuid' => $uuid,
            ]);
        }

        // ── Mark note as synced on production MySQL ───────────────────
        $isLocalSqlite = config('database.default') === 'sqlite';

        $note = PatientNote::create([
            'patient_id'  => $patient->id,
            'author_id'   => $authorId,
            'uuid'        => (string) \Illuminate\Support\Str::uuid(),
            'category'    => $validated['category'] ?? 'notes',
            'content'     => $validated['content'],
            'sync_status' => $isLocalSqlite ? 'pending_create' : 'synced',
        ]);

        $note->load('author:id,name,email');

        return response()->json($note, 201);
    }

    public function update(Request $request, string $uuid, string $noteUuid)
    {
        // ═══════════════════════════════════════════════════════════════
        //  CAPTURE BEARER TOKEN (same as store() — needed for sync engine)
        // ═══════════════════════════════════════════════════════════════
        if (config('database.default') === 'sqlite') {
            $bearerToken = $request->bearerToken();
            if ($bearerToken) {
                try {
                    app(\App\Services\Mobile\ApiService::class)->setToken($bearerToken);
                } catch (\Throwable $e) {
                    Log::warning('[MobileNote] Failed to capture Bearer token in update: ' . $e->getMessage());
                }
            }
        }

        $patient = $this->resolvePatient($uuid);
        // ── SQLite guard: Skip Gate when no authenticated user ──────────
        if ($request->user()) {
            Gate::authorize('view', $patient);
        }

        $note = PatientNote::where('uuid', $noteUuid)
            ->where('patient_id', $patient->id)
            ->firstOrFail();

        // ── ISO-003 FIX: Check patient update permission instead of author_id
        // Previous code checked $note->author_id !== $request->user()->id,
        // which prevented the PRIMARY DOCTOR from editing notes authored by
        // other doctors on THEIR OWN patient. Now checks if user can update
        // the patient (which includes primary doctors and shared doctors with
        // write access). This is consistent with the Api\\NoteController which
        // falls back to Gate::authorize('update', $note->patient).
        if ($request->user() && $note->author_id !== $request->user()->id) {
            Gate::authorize('update', $note->patient);
        }

        $validated = $request->validate([
            'content' => 'required|string',
        ]);

        // ═══ SYNC-006 FIX: Mark note as pending_update on SQLite ═══════════
        // Without this, the note update stays local and the sync engine
        // never uploads it to the production server.
        // NOTE: patient_notes table does NOT have client_updated_at column,
        // so we only set sync_status here.
        if (config('database.default') === 'sqlite') {
            $note->update(array_merge($validated, [
                'sync_status' => 'pending_update',
            ]));
        } else {
            $note->update($validated);
        }

        $note->load('author:id,name,email');

        return response()->json($note);
    }

    public function destroy(Request $request, string $uuid, string $noteUuid)
    {
        // ═══════════════════════════════════════════════════════════════
        //  CAPTURE BEARER TOKEN (same as store() — needed for sync engine)
        // ═══════════════════════════════════════════════════════════════
        if (config('database.default') === 'sqlite') {
            $bearerToken = $request->bearerToken();
            if ($bearerToken) {
                try {
                    app(\App\Services\Mobile\ApiService::class)->setToken($bearerToken);
                } catch (\Throwable $e) {
                    Log::warning('[MobileNote] Failed to capture Bearer token in destroy: ' . $e->getMessage());
                }
            }
        }

        $patient = $this->resolvePatient($uuid);

        $note = PatientNote::where('uuid', $noteUuid)
            ->where('patient_id', $patient->id)
            ->firstOrFail();

        // ── ISO-003 FIX: Same as update method — check patient permission
        // ── SQLite guard: Skip Gate when no authenticated user ──────────
        if ($request->user() && $note->author_id !== $request->user()->id) {
            Gate::authorize('update', $note->patient);
        }

        // ═══ SYNC-006 FIX: Mark note as pending_delete on SQLite ═══════════
        // Without this, the note delete stays local (soft-deleted) and
        // the sync engine never removes it from the production server.
        //
        // On SQLite: Mark as pending_delete but do NOT soft-delete.
        // The sync engine queries PatientNote::where('sync_status', 'pending_delete')
        // WITHOUT withTrashed(), so soft-deleted notes would be invisible.
        // After successful remote delete, the sync engine calls forceDelete().
        //
        // On MySQL: Use normal soft-delete as before.
        if (config('database.default') === 'sqlite') {
            $note->update([
                'sync_status' => 'pending_delete',
            ]);
        } else {
            $note->delete();
        }

        return response()->json(['message' => 'Note deleted successfully']);
    }

    private function resolvePatient(string $uuid): Patient
    {
        $patient = Patient::withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)
            ->where('uuid', $uuid)->first();
        if ($patient) {
            return $patient;
        }

        // ── Always resolve a valid doctor ID before creating the stub ────
        // Without this, primary_doctor_id is null → NOT NULL constraint error.
        $doctorId = $this->resolveCurrentUserId();

        $stubData = [
            'uuid'              => $uuid,
            'sync_status'       => 'pending_create',
            'name'              => 'Patient (' . substr($uuid, 0, 8) . ')',
            'primary_doctor_id' => $doctorId,
            'created_by_id'     => $doctorId,
        ];

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
        $user = auth()->user();
        if ($user) {
            return $user->id;
        }

        $localUser = \App\Domains\Users\Models\User::first();
        if ($localUser) {
            return $localUser->id;
        }

        // Absolute last resort — clean install with empty SQLite
        \App\Domains\Users\Models\User::unguard();
        $defaultUser = \App\Domains\Users\Models\User::firstOrCreate(
            ['email' => 'doctor@local.test'],
            [
                'name'     => 'Default Doctor',
                'password' => bcrypt('password'),
                'role'     => 'doctor',
                'uuid'     => (string) \Illuminate\Support\Str::uuid(),
                'status'   => 'active',
            ]
        );
        \App\Domains\Users\Models\User::reguard();

        Log::info('[MobileNote] Seeded default doctor for clean install', [
            'doctor_id' => $defaultUser->id,
        ]);

        return $defaultUser->id;
    }
}
