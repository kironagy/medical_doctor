<?php

namespace App\Http\Controllers\Api;

use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class NoteController extends Controller
{
    public function index(Request $request, string $patientUuid)
    {
        $patient = $this->resolvePatient($patientUuid);
        $notes = $patient->notes()
            ->with('author:id,name,email')
            ->latest()
            ->get();
        return response()->json($notes);
    }

    public function store(Request $request, string $patientUuid)
    {
        $user = $request->user();
        $patient = $this->resolvePatient($patientUuid);

        $validated = $request->validate([
            'content' => 'required|string',
            'category' => 'nullable|string|max:100',
        ]);

        try {
        $noteData = [
            'content' => $validated['content'],
            'category' => $validated['category'] ?? 'notes',
        ];

            if ($user) {
                $noteData['author_id'] = $user->id;
            } else {
                $noteData['author_id'] = $validated['author_id'] ?? $patient->primary_doctor_id;

                if (!$noteData['author_id']) {
                    Log::error('[NoteController] Cannot create offline note: no user found', [
                        'patient_uuid' => $patientUuid,
                    ]);
                    return response()->json(['message' => 'Cannot create note offline. Please login and sync first.'], 500);
                }
            }

            // ── FIX: On embedded Laravel (SQLite), ALWAYS mark notes as pending sync ──
            // On the embedded Laravel, notes are saved to LOCAL SQLite only. They must
            // be synced to the production server when connectivity returns. Without this,
            // the default sync_status='synced' is applied, and SyncEngineService never
            // picks them up because it queries WHERE sync_status='pending_create'.
            $isLocalSqlite = config('database.default') === 'sqlite';
            if ($isLocalSqlite) {
                $noteData['sync_status'] = 'pending_create';
            }

            $note = $patient->notes()->create($noteData);
            $note->load('author:id,name,email');

            return response()->json($note);
        } catch (\Throwable $e) {
            Log::error('[NoteController] store failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to create note'], 500);
        }
    }

    public function update(Request $request, string $patientUuid, string $uuid)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $note = PatientNote::with('patient')->where('uuid', $uuid)->firstOrFail();

        if ($note->author_id !== $user->id) {
            Gate::authorize('update', $note->patient);
        }

        $validated = $request->validate([
            'content' => 'required|string',
        ]);

        $note->update(['content' => $validated['content']]);
        $note->load('author:id,name,email');

        return response()->json($note);
    }

    public function destroy(Request $request, string $patientUuid, string $uuid)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $note = PatientNote::with('patient')->where('uuid', $uuid)->firstOrFail();

        if ($note->author_id !== $user->id) {
            Gate::authorize('update', $note->patient);
        }

        $note->delete();

        return response()->json(['message' => 'Note deleted']);
    }

    private function resolvePatient(string $uuid): Patient
    {
        // SYNC-007 bypass — see Mobile\FileController::resolvePatient(): with
        // the scope applied an existing patient reads as missing and the stub
        // below overwrites it with a "Patient (xxxxxxxx)" placeholder.
        $patient = Patient::withoutGlobalScope(
            \App\Domains\Auth\Scopes\DoctorIsolationScope::class
        )->where(function ($q) use ($uuid) {
            $q->where('uuid', $uuid)->orWhere('remote_uuid', $uuid);
        })->first();

        if ($patient) {
            return $patient;
        }

        // ── NOT NULL FIX (500 error on fresh install) ─────────────────────
        // On a clean install the local users table can be EMPTY. Using only
        // auth()->id() left primary_doctor_id null, causing SQLite
        // 'NOT NULL constraint failed: patients.primary_doctor_id' → 500.
        $userId = $this->resolveCurrentUserId();

        $stubData = [
            'uuid'              => $uuid,
            'sync_status'       => 'pending_create',
            'name'              => 'Patient (' . substr($uuid, 0, 8) . ')',
            'primary_doctor_id' => $userId,
            'created_by_id'     => $userId,
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

        // Absolute last resort: seed default doctor on clean install
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

        Log::info('[NoteController] Seeded default doctor for clean install', [
            'doctor_id' => $defaultUser->id,
        ]);

        return $defaultUser->id;
    }
}
