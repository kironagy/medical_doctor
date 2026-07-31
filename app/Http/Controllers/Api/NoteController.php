<?php

namespace App\Http\Controllers\Api;

use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use App\Http\Controllers\Controller;
use App\Repositories\Api\ApiPatientRepository;
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
        $patient = Patient::where('uuid', $uuid)->first();
        if ($patient) {
            return $patient;
        }

        try {
            $apiPatient = app(ApiPatientRepository::class)->find($uuid);
            if ($apiPatient) {
                $cleanData = \Illuminate\Support\Arr::except($apiPatient, [
                    'id', 'primary_doctor', 'visits', 'shares', 'files', 'notes',
                ]);
                $cleanData['sync_status'] = 'synced';

                Patient::unguard();
                $patient = Patient::updateOrCreate(['uuid' => $uuid], $cleanData);
                Patient::reguard();

                if ($patient) {
                    return $patient;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('resolvePatient API fallback failed', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);
        }

        // ── SEC-003 FIX: When creating a stub patient, set primary_doctor_id
        // from the current authenticated user if available. Previously this
        // created patients with NULL primary_doctor_id, which made them
        // visible to ALL doctors. Now only the authenticated user sees them.
        $stubData = [
            'uuid' => $uuid,
            'sync_status' => 'pending_sync',
            'name' => 'Patient (' . $uuid . ')',
        ];
        $userId = auth()->id();
        if (!$userId && config('database.default') === 'sqlite') {
            $user = \App\Domains\Users\Models\User::first();
            if (!$user) {
                \App\Domains\Users\Models\User::unguard();
                $user = \App\Domains\Users\Models\User::firstOrCreate(
                    ['id' => 1],
                    [
                        'name' => 'Default Doctor',
                        'email' => 'doctor@local.test',
                        'password' => bcrypt('password'),
                    ]
                );
                \App\Domains\Users\Models\User::reguard();
            }
            $userId = $user->id;
        }

        if ($userId) {
            $stubData['primary_doctor_id'] = $userId;
            $stubData['created_by_id'] = $userId;
        }

        $patient = Patient::updateOrCreate(
            ['uuid' => $uuid],
            $stubData
        );

        return $patient;
    }
}
