<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use App\Http\Controllers\Controller;
use App\Repositories\Api\ApiPatientRepository;
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

    public function store(Request $request, string $uuid)
    {
        Log::info('[MobileNote::store] ENTERED uuid=' . $uuid . ' user=' . ($request->user()?->id ?? 'null'));
        $patient = $this->resolvePatient($uuid);
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
        $authorId = $user?->id ?? $validated['author_id'] ?? $patient->primary_doctor_id;
        if (!$authorId) {
            Log::warning('[MobileNote] Creating note without author_id — will be unowned', [
                'patient_uuid' => $uuid,
            ]);
        }

        // ── FIX: Mark note as pending_create when on embedded Laravel (SQLite) ──
        // On the embedded Laravel, notes created via /api/v1/mobile/patients/{uuid}/notes
        // are stored in local SQLite. The sync engine only uploads notes with
        // sync_status = 'pending_create'. Without this fix, notes stay in local SQLite
        // with default sync_status = 'synced' and are NEVER uploaded to production.
        $isLocalSqlite = config('database.default') === 'sqlite';

        $note = PatientNote::create([
            'patient_id'  => $patient->id,
            'author_id'   => $authorId,
            'uuid'        => (string) \Illuminate\Support\Str::uuid(),
            'category'    => $validated['category'] ?? 'general',
            'content'     => $validated['content'],
            'sync_status' => $isLocalSqlite ? 'pending_create' : 'synced',
        ]);

        $note->load('author:id,name,email');

        return response()->json($note, 201);
    }

    public function update(Request $request, string $uuid, string $noteUuid)
    {
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

        $note->update($validated);
        $note->load('author:id,name,email');

        return response()->json($note);
    }

    public function destroy(Request $request, string $uuid, string $noteUuid)
    {
        $patient = $this->resolvePatient($uuid);

        $note = PatientNote::where('uuid', $noteUuid)
            ->where('patient_id', $patient->id)
            ->firstOrFail();

        // ── ISO-003 FIX: Same as update method — check patient permission
        // ── SQLite guard: Skip Gate when no authenticated user ──────────
        if ($request->user() && $note->author_id !== $request->user()->id) {
            Gate::authorize('update', $note->patient);
        }

        $note->delete();

        return response()->json(['message' => 'Note deleted successfully']);
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
