<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
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
        private readonly ApiService $api
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

        $notes = $patient->notes()
            ->with('author:id,name,email')
            ->latest()
            ->paginate(50);

        return MobilePatientNoteResource::collection($notes);
    }

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

        $note = PatientNote::create([
            'patient_id' => $patient->id,
            'author_id' => $request->user()->id,
            'category' => $validated['category'] ?? 'general',
            'content' => $validated['content'],
        ]);

        $note->load('author:id,name,email');

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

        $note = PatientNote::where('uuid', $noteUuid)
            ->where('patient_id', $patient->id)
            ->firstOrFail();

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
        if (NativePhp::isRunning() && NetworkStatusService::isOnline()) {
            try {
                $this->api->delete("/patients/{$uuid}/notes/{$noteUuid}");
                return response()->json(['message' => 'Note deleted successfully']);
            } catch (\Throwable $e) {
                Log::warning('[NoteController] API delete failed, falling back to local: ' . $e->getMessage());
            }
        }

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
