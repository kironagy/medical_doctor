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
        
        // ── Guard: user must be authenticated ──────────────────────────────
        // When offline and session is lost, $request->user() is null and
        // accessing ->id would throw a 500 error ("حدث خطأ في الإدارة").
        $user = $request->user();
        if (!$user) {
            @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' N1c USER NULL 401' . "\n", FILE_APPEND | LOCK_EX);
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' N2 Auth OK, lookup patient' . "\n", FILE_APPEND | LOCK_EX);
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
            $note = $patient->notes()->create([
                'author_id' => $user->id,
                'content' => $validated['content'],
                'category' => $validated['category'] ?? null,
            ]);
            @file_put_contents('/data/local/tmp/np_traces.txt', now()->format('H:i:s.v') . ' N6 Note created id=' . $note->id . ' uuid=' . $note->uuid . "\n", FILE_APPEND | LOCK_EX);

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
