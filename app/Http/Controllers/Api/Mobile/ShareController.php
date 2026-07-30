<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientShare;
use App\Domains\Users\Models\User;
use App\Domains\ActivityLogs\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ShareController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger
    ) {}

    public function index(string $uuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('view', $patient);

        $shares = $patient->shares()
            ->with('doctor:id,name,email,specialization')
            ->latest()
            ->get();

        return response()->json($shares);
    }

    public function store(Request $request, string $uuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('update', $patient);

        $validated = $request->validate([
            'doctor_id' => ['required', 'exists:users,id'],
            'access_level' => ['required', Rule::in(['read', 'read_write'])],
            'expires_at' => 'nullable|date|after:today',
        ]);

        $doctor = User::findOrFail($validated['doctor_id']);
        if (!$doctor->hasRole('doctor')) {
            return response()->json(['message' => 'Target user must be a doctor'], 422);
        }

        $share = PatientShare::updateOrCreate(
            [
                'patient_id' => $patient->id,
                'doctor_id' => $validated['doctor_id'],
            ],
            [
                'shared_by_id' => $request->user()?->id ?? $patient->primary_doctor_id ?? 1,
                'access_level' => $validated['access_level'],
                'expires_at' => $validated['expires_at'] ?? null,
            ]
        );

        $share->load('doctor:id,name,email,specialization');

        $this->logger->log('patient_shared', 'PatientShare', null, [
            'patient_uuid' => $uuid,
            'doctor_id' => $validated['doctor_id'],
        ]);

        return response()->json($share, 201);
    }

    public function destroy(string $uuid, string $shareId)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('update', $patient);

        $share = PatientShare::where('patient_id', $patient->id)
            ->where('id', $shareId)
            ->firstOrFail();

        $share->delete();

        return response()->json(['message' => 'Share removed successfully']);
    }
}
