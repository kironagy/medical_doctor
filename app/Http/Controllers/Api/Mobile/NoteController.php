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
        ]);

        $note = PatientNote::create([
            'patient_id' => $patient->id,
            'author_id' => $request->user()->id,
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
