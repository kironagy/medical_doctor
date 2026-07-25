<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NoteController extends Controller
{
    public function index(string $uuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('view', $patient);

        $notes = $patient->notes()
            ->with('author:id,name,email')
            ->latest()
            ->paginate(50);

        return response()->json($notes);
    }

    public function store(Request $request, string $uuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('update', $patient);

        $validated = $request->validate([
            'content' => 'required|string',
            'category' => 'nullable|string|max:100',
            // author_id accepted from request body so the mobile sync engine
            // can set the correct author even when this endpoint is accessed
            // without auth:sanctum middleware (temporary debugging bypass).
            'author_id' => 'nullable|integer',
        ]);

        // ── Manual Bearer Token Resolution ──────────────────────────────
        // auth:sanctum middleware is temporarily removed from this endpoint
        // (same as patient creation). $request->user() cannot resolve the
        // Bearer token via middleware, so we must resolve it manually.
        $user = $request->user();
        if (!$user) {
            $bearerToken = $request->bearerToken();
            if ($bearerToken) {
                $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($bearerToken);
                if ($accessToken && $accessToken->tokenable) {
                    $user = $accessToken->tokenable;
                }
            }
        }

        $authorId = $user?->id ?? $validated['author_id'] ?? $patient->primary_doctor_id;
        if (!$authorId) {
            \Illuminate\Support\Facades\Log::warning('[MobileNote] Creating note without author_id — will be unowned', [
                'patient_uuid' => $uuid,
            ]);
        }

        $note = PatientNote::create([
            'patient_id' => $patient->id,
            'author_id' => $authorId,
            'category' => $validated['category'] ?? 'general',
            'content' => $validated['content'],
        ]);

        $note->load('author:id,name,email');

        return response()->json($note, 201);
    }

    public function update(Request $request, string $uuid, string $noteUuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('view', $patient);

        $note = PatientNote::where('uuid', $noteUuid)
            ->where('patient_id', $patient->id)
            ->firstOrFail();

        if ($note->author_id !== $request->user()->id) {
            abort(403, 'You can only edit your own notes.');
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
        $patient = Patient::where('uuid', $uuid)->firstOrFail();

        $note = PatientNote::where('uuid', $noteUuid)
            ->where('patient_id', $patient->id)
            ->firstOrFail();

        if ($note->author_id !== $request->user()->id) {
            abort(403, 'You can only delete your own notes.');
        }

        $note->delete();

        return response()->json(['message' => 'Note deleted successfully']);
    }
}
