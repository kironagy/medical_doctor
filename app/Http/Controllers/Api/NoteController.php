<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NoteController extends Controller
{
    public function index(Request $request, string $patientUuid)
    {
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        $notes = $patient->notes()
            ->with('author:id,name,email')
            ->latest()
            ->get();
        return response()->json($notes);
    }

    public function store(Request $request, string $patientUuid)
    {
        $tf = '/data/local/tmp/np_traces.txt';
        @file_put_contents($tf, now()->format('H:i:s.v') . ' N1 NoteController.store() ENTERED uuid=' . $patientUuid . "\n", FILE_APPEND | LOCK_EX);
        @file_put_contents($tf, now()->format('H:i:s.v') . ' N1b session user=' . ($request->user() ? 'yes' : 'no') . ' id=' . ($request->user()?->id ?? 'null') . "\n", FILE_APPEND | LOCK_EX);

        // ── Auth guard: try session first, then Bearer token ────────────────
        // When offline or on app restart, the embedded Laravel has no session.
        // The Sanctum token from localStorage may not resolve to a user in the
        // local SQLite. In those cases, we allow offline note creation without
        // a user — the note is saved with sync_status='pending_create' and the
        // author_id is assigned when the SyncEngine pushes it to the production
        // server.
        $user = $request->user();
        if (!$user) {
            @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' N1c USER NULL - trying Bearer token' . "\n", FILE_APPEND | LOCK_EX);

            // Try to authenticate via Sanctum Bearer token (stored in localStorage)
            $bearerToken = $request->bearerToken();
            if ($bearerToken) {
                try {
                    $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($bearerToken);
                    if ($accessToken && $accessToken->tokenable) {
                        \Illuminate\Support\Facades\Auth::login($accessToken->tokenable);
                        $user = $accessToken->tokenable;
                        @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' N1c2 Auth via Bearer: user_id=' . $user->id . "\n", FILE_APPEND | LOCK_EX);
                    }
                } catch (\Throwable $e) {
                    @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' N1c3 Bearer auth failed: ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
                }
            }

            if (!$user) {
                @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' N1c4 No auth - saving offline without author' . "\n", FILE_APPEND | LOCK_EX);
            }
        }

        @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' N2 Lookup patient' . "\n", FILE_APPEND | LOCK_EX);
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' N3 Patient found id=' . $patient->id . "\n", FILE_APPEND | LOCK_EX);

        // Validate BEFORE try/catch — ValidationException must return 422, not 500
        $validated = $request->validate([
            'content' => 'required|string',
            'category' => 'nullable|string|max:100',
        ]);
        @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' N4 Validation passed' . "\n", FILE_APPEND | LOCK_EX);

        try {
            @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' N5 Creating note' . "\n", FILE_APPEND | LOCK_EX);

            $noteData = [
                'content' => $validated['content'],
                'category' => $validated['category'] ?? null,
            ];

            // ── Resolve author_id ────────────────────────────────────────────
            // When authenticated via session or Bearer token, use the logged-in user.
            // When offline (no auth), resolve from request body or fall back to the
            // patient's primary doctor. If both are null (offline-created patient
            // with no doctor), try the first user in the local DB as last resort.
            // author_id is NOT NULL with a FK constraint in the schema, so we MUST
            // provide a value or the DB throws a constraint violation -> 500 error.
            if ($user) {
                $noteData['author_id'] = $user->id;
            } else {
                $noteData['author_id'] = $validated['author_id'] ?? $patient->primary_doctor_id;

                // Last resort: try any user in the local SQLite
                if (!$noteData['author_id']) {
                    $fallbackUser = \App\Domains\Users\Models\User::query()->first();
                    if ($fallbackUser) {
                        $noteData['author_id'] = $fallbackUser->id;
                        \Illuminate\Support\Facades\Log::info('[NoteController] Used fallback user for offline note', [
                            'patient_uuid' => $patientUuid,
                            'fallback_user_id' => $fallbackUser->id,
                        ]);
                    } else {
                        // No users at all in local DB — can't create note
                        \Illuminate\Support\Facades\Log::error('[NoteController] Cannot create offline note: no user found', [
                            'patient_uuid' => $patientUuid,
                        ]);
                        return response()->json(['message' => 'Cannot create note offline. Please login and sync first.'], 500);
                    }
                }
            }

            // Always set pending_create so the SyncEngine pushes to the remote server.
            // Without this, the note only lives in the local SQLite and never reaches
            // the production database — a silent data loss bug.
            $noteData['sync_status'] = 'pending_create';

            $note = $patient->notes()->create($noteData);
            @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' N6 Note created id=' . $note->id . ' uuid=' . $note->uuid . ' sync_status=' . $noteData['sync_status'] . "\n", FILE_APPEND | LOCK_EX);

            $note->load('author:id,name,email');

            return response()->json($note);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[NoteController] store failed: ' . $e->getMessage());
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
}
