<?php

namespace App\Http\Controllers\Api;

use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use App\Http\Controllers\Controller;
use App\Repositories\Api\ApiPatientRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        if (!$user) {
            $bearerToken = $request->bearerToken();
            if ($bearerToken) {
                try {
                    $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($bearerToken);
                    if ($accessToken && $accessToken->tokenable) {
                        Auth::login($accessToken->tokenable);
                        $user = $accessToken->tokenable;
                    }
                } catch (\Throwable $e) {
                    Log::warning('[NoteController] Bearer auth failed: ' . $e->getMessage());
                }
            }
        }

        $patient = $this->resolvePatient($patientUuid);

        $validated = $request->validate([
            'content' => 'required|string',
            'category' => 'nullable|string|max:100',
        ]);

        try {
            $noteData = [
                'content' => $validated['content'],
                'category' => $validated['category'] ?? null,
            ];

            if ($user) {
                // ── Authenticated user (production server or online embedded Laravel) ──
                // The note is created on the production DB directly.
                // sync_status is NOT set because the production DB doesn't have this column.
                // sync_status is an offline-only concept for the embedded Laravel SQLite.
                $noteData['author_id'] = $user->id;
            } else {
                // ── No authenticated user (offline / embedded Laravel) ──
                // The note is saved to the local SQLite with sync_status = 'pending_create'
                // so the SyncEngine can push it to the production server later.
                $noteData['author_id'] = $validated['author_id'] ?? $patient->primary_doctor_id;
                $noteData['sync_status'] = 'pending_create';

                if (!$noteData['author_id']) {
                    $fallbackUser = \App\Domains\Users\Models\User::query()->first();
                    if ($fallbackUser) {
                        $noteData['author_id'] = $fallbackUser->id;
                        Log::info('[NoteController] Used fallback user for offline note', [
                            'patient_uuid' => $patientUuid,
                            'fallback_user_id' => $fallbackUser->id,
                        ]);
                    } else {
                        Log::error('[NoteController] Cannot create offline note: no user found', [
                            'patient_uuid' => $patientUuid,
                        ]);
                        return response()->json(['message' => 'Cannot create note offline. Please login and sync first.'], 500);
                    }
                }
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

        $patient = Patient::updateOrCreate(
            ['uuid' => $uuid],
            [
                'uuid' => $uuid,
                'sync_status' => 'pending_sync',
                'first_name' => 'Patient',
                'last_name' => $uuid,
            ]
        );

        return $patient;
    }
}
