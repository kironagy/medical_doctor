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
        // ── Guard: user must be authenticated ──────────────────────────────
        // When offline and session is lost, $request->user() is null and
        // accessing ->id would throw a 500 error ("حدث خطأ في الإدارة").
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();

        $validated = $request->validate([
            'content' => 'required|string',
            'category' => 'nullable|string|max:100',
        ]);

        try {
            $note = $patient->notes()->create([
                'author_id' => $user->id,
                'content' => $validated['content'],
                'category' => $validated['category'] ?? null,
            ]);

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
