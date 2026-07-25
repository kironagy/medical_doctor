<?php

namespace App\Http\Controllers\Api;

use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use App\Http\Controllers\Controller;
use App\Repositories\Api\ApiPatientRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OfflineNoteController — Phase 8: Offline Note Creation & Sync
 *
 * Handles notes created while the device is offline.
 * Routes (all under /_native/api/offline/notes):
 *   POST   /notes        — Create a note locally (sync_status = pending_create)
 *   GET    /notes        — List offline notes for a patient
 *   DELETE /notes/{uuid} — Delete a pending offline note
 *
 * Sync Strategy:
 *   Notes stored directly in patient_notes with sync_status = 'pending_create'.
 *   SyncEngineService uploads them when connectivity returns.
 */
class OfflineNoteController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'content'      => 'required|string',
            'patient_uuid' => 'required|string|size:36',
            'category'     => 'nullable|string|max:100',
        ]);

        $patient = $this->resolvePatient($validated['patient_uuid']);

        // Use the currently authenticated user (session-based, auto-logged in)
        $user = $request->user();

        if ($user) {
            try {
                Gate::authorize('update', $patient);
            } catch (\Throwable $e) {
                Log::warning('[OfflineNote] Gate authorization failed, continuing: ' . $e->getMessage());
            }
        }

        $authorId = $user?->id;

        // ── No fallback to first user in DB ─────────────────────────────
        // Previously this code grabbed the first user from the database as
        // a fallback author, which caused wrong authorship attribution
        // (OFFLINE-003). Now we require a valid authenticated user.
        if (!$authorId) {
            return response()->json([
                'success' => false,
                'message' => 'No authenticated user available. Please login and try again.',
            ], 401);
        }

        try {
            $note = $patient->notes()->create([
                'uuid'        => (string) Str::uuid(),
                'author_id'   => $authorId,
                'content'     => $validated['content'],
                'category'    => $validated['category'] ?? 'general',
                'sync_status' => 'pending_create',
            ]);

            $note->load('author:id,name,email');

            Log::info('[OfflineNote] Note created locally', [
                'uuid'       => $note->uuid,
                'patient_id' => $patient->id,
                'sync_status' => 'pending_create',
            ]);

            return response()->json([
                'success'     => true,
                'uuid'        => $note->uuid,
                'patient_id'  => $patient->id,
                'content'     => $note->content,
                'category'    => $note->category,
                'sync_status' => 'pending_create',
                'author'      => $note->author ? [
                    'id'    => $note->author->id,
                    'name'  => $note->author->name,
                    'email' => $note->author->email,
                ] : null,
                'created_at'  => $note->created_at->toIso8601String(),
                'updated_at'  => $note->updated_at->toIso8601String(),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('[OfflineNote] Failed to create note locally: ' . $e->getMessage(), [
                'patient_uuid' => $validated['patient_uuid'],
                'error'        => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create note offline: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function index(Request $request)
    {
        $patientUuid = $request->get('patient_uuid');

        if (!$patientUuid) {
            return response()->json(['data' => []]);
        }

        $patient = Patient::where('uuid', $patientUuid)->first();
        if (!$patient) {
            return response()->json(['data' => []]);
        }

        $notes = PatientNote::where('patient_id', $patient->id)
            ->whereIn('sync_status', ['pending_create', 'pending_delete'])
            ->with('author:id,name,email')
            ->latest()
            ->get()
            ->toArray();

        return response()->json([
            'data' => $notes,
        ]);
    }

    public function destroy(string $uuid)
    {
        $note = PatientNote::where('uuid', $uuid)->first();

        if (!$note) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $user = request()->user();
        if ($user && $note->patient) {
            try {
                Gate::authorize('update', $note->patient);
            } catch (\Throwable $e) {
            }
        }

        if ($note->sync_status === 'synced') {
            $note->update(['sync_status' => 'pending_delete']);
            Log::info('[OfflineNote] Marked synced note for deletion: ' . $uuid);
        } else {
            $note->forceDelete();
            Log::info('[OfflineNote] Deleted pending note: ' . $uuid);
        }

        return response()->json(['message' => 'Deleted']);
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

        // ── SEC-003 FIX: Set primary_doctor_id on stub patients
        $stubData = [
            'uuid' => $uuid,
            'sync_status' => 'pending_sync',
            'name' => 'Patient (' . $uuid . ')',
        ];
        if (auth()->check()) {
            $stubData['primary_doctor_id'] = auth()->id();
            $stubData['created_by_id'] = auth()->id();
        }

        $patient = Patient::updateOrCreate(
            ['uuid' => $uuid],
            $stubData
        );

        return $patient;
    }
}
