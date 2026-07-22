<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use App\Domains\Mobile\Resources\MobilePatientNoteResource;
use App\Helpers\NativePhp;
use App\Services\NetworkStatusService;
use App\Services\Mobile\ApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class NoteController extends Controller
{
    public function __construct(
        private readonly ApiService $api,
        private readonly PatientNoteRepositoryInterface $noteRepo,
        private readonly PatientRepositoryInterface $patientRepo
    ) {}

    public function index(string $uuid)
    {
        if (NativePhp::isRunning() && NetworkStatusService::isOnline()) {
            try {
                $response = $this->api->get("/patients/{$uuid}/notes");
                $notes = $response['data'] ?? $response;
                $this->cacheNotesLocally($uuid, $notes);
                return response()->json($response);
            } catch (\Throwable $e) {
                Log::warning('[NoteController] API index failed, falling back to local: ' . $e->getMessage());
            }
        }

        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('view', $patient);

        $notes = $patient->notes()->with('author:id,name,email')->latest()->get();
        return response()->json([
            'data' => $notes->map(fn($n) => new MobilePatientNoteResource($n))->values(),
        ]);

    public function store(Request $request, string $uuid)
    {
        if (NativePhp::isRunning() && NetworkStatusService::isOnline()) {
            try {
                $validated = $request->validate([
                    'content' => 'required|string|max:65535',
                    'category' => 'nullable|string|max:100',
                ]);

                $response = $this->api->post("/patients/{$uuid}/notes", $validated);
                $this->cacheNotesLocally($uuid, [$response['data'] ?? $response]);
                return response()->json($response, 201);
            } catch (\Throwable $e) {
                Log::warning('[NoteController] API store failed, falling back to local: ' . $e->getMessage());
            }
        }

        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('update', $patient);

        $validated = $request->validate([
            'content' => 'required|string|max:65535',
            'category' => 'nullable|string|max:100',
        ]);

        $validated['patient_id'] = $patient->id;
        $validated['author_id'] = $request->user()->id;
        $result = $this->noteRepo->create($uuid, $validated);

        // Build model for resource formatting
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
        if (NativePhp::isRunning() && NetworkStatusService::isOnline()) {
            try {
                $validated = $request->validate([
                    'content' => 'required|string|max:65535',
                ]);

                $response = $this->api->put("/patients/{$uuid}/notes/{$noteUuid}", $validated);
                $this->cacheNotesLocally($uuid, [$response['data'] ?? $response]);
                return response()->json($response);
            } catch (\Throwable $e) {
                Log::warning('[NoteController] API update failed, falling back to local: ' . $e->getMessage());
            }
        }

        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('view', $patient);

        // Author check: repository doesn't enforce ownership, so check manually
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
        if (NativePhp::isRunning() && NetworkStatusService::isOnline()) {
            try {
                $this->api->delete("/patients/{$uuid}/notes/{$noteUuid}");
                return response()->json(['message' => 'Note deleted successfully']);
            } catch (\Throwable $e) {
                Log::warning('[NoteController] API delete failed, falling back to local: ' . $e->getMessage());
            }
        }

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

    private function cacheNotesLocally(string $patientUuid, array $notes): void
    {
        $patient = Patient::where('uuid', $patientUuid)->first();
        if (!$patient) return;

        foreach ($notes as $note) {
            if (!isset($note['uuid'])) continue;

            $cleanData = \Illuminate\Support\Arr::except($note, ['id', 'patient', 'author']);
            $cleanData['patient_id'] = $patient->id;

            try {
                PatientNote::withoutGlobalScopes()->updateOrCreate(
                    ['uuid' => $note['uuid']],
                    $cleanData
                );
            } catch (\Throwable $e) {
                Log::warning('[NoteController] Failed to cache note locally: ' . $e->getMessage());
            }
        }
    }
}
