<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use Illuminate\Http\Request;

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
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();

        $validated = $request->validate([
            'content' => 'required|string',
            'category' => 'nullable|string|max:100',
        ]);

        $note = $patient->notes()->create([
            'author_id' => $request->user()->id,
            'content' => $validated['content'],
            'category' => $validated['category'] ?? null,
        ]);

        $note->load('author:id,name,email');

        return response()->json($note);
    }

    public function update(Request $request, string $patientUuid, string $uuid)
    {
        $note = PatientNote::where('uuid', $uuid)->firstOrFail();

        abort_if($note->author_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'content' => 'required|string',
        ]);

        $note->update(['content' => $validated['content']]);
        $note->load('author:id,name,email');

        return response()->json($note);
    }

    public function destroy(Request $request, string $patientUuid, string $uuid)
    {
        $note = PatientNote::where('uuid', $uuid)->firstOrFail();

        abort_if($note->author_id !== $request->user()->id, 403);

        $note->delete();

        return response()->json(['message' => 'Note deleted']);
    }
}
