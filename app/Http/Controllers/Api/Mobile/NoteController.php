<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use App\Domains\Mobile\Resources\MobilePatientNoteResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NoteController extends Controller
{
    public function index(string $uuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('view', $patient);

        $notes = $patient->notes()->with('author:id,name,email')->latest()->get();
        return response()->json([
            'data' => $notes->map(fn($n) => new MobilePatientNoteResource($n))->values(),
        ]);
    }

    public function store(Request $request, string $uuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('update', $patient);

        $validated = $request->validate([
            'content' => 'required|string|max:65535',
            'category' => 'nullable|string|max:100',
        ]);

        $note = $patient->notes()->create([
            'content' => $validated['content'],
            'category' => $validated['category'] ?? 'general',
            'author_id' => $request->user()->id,
        ]);

        $note->load('author:id,name,email');

        return response()->json(new MobilePatientNoteResource($note), 201);
    }

    public function update(Request $request, string $uuid, string $noteUuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('view', $patient);

        $note = PatientNote::where('uuid', $noteUuid)->where('patient_id', $patient->id)->firstOrFail();

        if ($note->author_id !== $request->user()->id) {
            abort(403, 'You can only edit your own notes.');
        }

        $validated = $request->validate([
            'content' => 'required|string|max:65535',
        ]);

        $note->update($validated);
        $note->load('author:id,name,email');

        return response()->json(new MobilePatientNoteResource($note));
    }

    public function destroy(Request $request, string $uuid, string $noteUuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();

        $note = PatientNote::where('uuid', $noteUuid)->where('patient_id', $patient->id)->firstOrFail();

        if ($note->author_id !== $request->user()->id) {
            abort(403, 'You can only delete your own notes.');
        }

        $note->delete();
        return response()->json(['message' => 'Note deleted successfully']);
    }
}
