<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use App\Domains\Mobile\Resources\MobilePatientNoteResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NoteController extends Controller
{
    public function __construct(
        private readonly PatientNoteRepositoryInterface $noteRepo,
        private readonly PatientRepositoryInterface $patientRepo
    ) {}

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

        $validated['patient_id'] = $patient->id;
        $validated['author_id'] = $request->user()->id;
        $result = $this->noteRepo->create($uuid, $validated);

        $note = new PatientNote();
        $note->forceFill(\Illuminate\Support\Arr::except($result, ['author']));
        $note->exists = true;
        if (isset($result['author']) && is_array($result['author'])) {
            $note->setRelation('author', (new \App\Domains\Users\Models\User())->forceFill($result['author']));
        }

        return response()->json(new MobilePatientNoteResource($note), 201);
    }

    public function update(Request $request, string $uuid, string $noteUuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('view', $patient);

        $noteData = $this->noteRepo->forPatient($uuid);
        $noteData = collect($noteData)->firstWhere('uuid', $noteUuid);
        if (!$noteData) abort(404);

        if (($noteData['author_id'] ?? null) !== $request->user()->id) {
            abort(403, 'You can only edit your own notes.');
        }

        $validated = $request->validate([
            'content' => 'required|string|max:65535',
        ]);

        $result = $this->noteRepo->update($uuid, $noteUuid, $validated);

        $note = new PatientNote();
        $note->forceFill(\Illuminate\Support\Arr::except($result, ['author']));
        $note->exists = true;
        if (isset($result['author']) && is_array($result['author'])) {
            $note->setRelation('author', (new \App\Domains\Users\Models\User())->forceFill($result['author']));
        }

        return response()->json(new MobilePatientNoteResource($note));
    }

    public function destroy(Request $request, string $uuid, string $noteUuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        $noteData = $this->noteRepo->forPatient($uuid);
        $noteData = collect($noteData)->firstWhere('uuid', $noteUuid);
        if (!$noteData) abort(404);

        if (($noteData['author_id'] ?? null) !== $request->user()->id) {
            abort(403, 'You can only delete your own notes.');
        }

        $this->noteRepo->delete($uuid, $noteUuid);
        return response()->json(['message' => 'Note deleted successfully']);
    }
}
