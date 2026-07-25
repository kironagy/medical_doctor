<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use App\Http\Controllers\Controller;
use App\Repositories\Api\ApiPatientRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class NoteController extends Controller
{
    public function index(string $uuid)
    {
        $patient = $this->resolvePatient($uuid);
        Gate::authorize('view', $patient);

        $notes = $patient->notes()
            ->with('author:id,name,email')
            ->latest()
            ->paginate(50);

        return response()->json($notes);
    }

    public function store(Request $request, string $uuid)
    {
        Log::info('[MobileNote::store] ENTERED uuid=' . $uuid . ' user=' . ($request->user()?->id ?? 'null') . ' online=' . (config('database.connections.sqlite.database') ?? '?'));
        $patient = $this->resolvePatient($uuid);
        Gate::authorize('update', $patient);

        $validated = $request->validate([
            'content' => 'required|string',
            'category' => 'nullable|string|max:100',
            'author_id' => 'nullable|integer',
        ]);

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
            Log::warning('[MobileNote] Creating note without author_id — will be unowned', [
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
        $patient = $this->resolvePatient($uuid);
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
        $patient = $this->resolvePatient($uuid);

        $note = PatientNote::where('uuid', $noteUuid)
            ->where('patient_id', $patient->id)
            ->firstOrFail();

        if ($note->author_id !== $request->user()->id) {
            abort(403, 'You can only delete your own notes.');
        }

        $note->delete();

        return response()->json(['message' => 'Note deleted successfully']);
    }

    private function resolvePatient(string $uuid): Patient
    {
        $patient = Patient::where('uuid', $uuid)->first();
        if ($patient) {
            return $patient;
        }

        $apiPatient = app(ApiPatientRepository::class)->find($uuid);
        if (!$apiPatient) {
            abort(404, 'Patient not found');
        }

        $cleanData = \Illuminate\Support\Arr::except($apiPatient, [
            'id', 'primary_doctor', 'visits', 'shares', 'files', 'notes',
        ]);
        $cleanData['sync_status'] = 'synced';

        Patient::unguard();
        $patient = Patient::updateOrCreate(['uuid' => $uuid], $cleanData);
        Patient::reguard();

        if (!$patient) {
            abort(404, 'Patient not found');
        }

        return $patient;
    }
}
