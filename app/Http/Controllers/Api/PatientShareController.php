<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientShare;
use App\Domains\Users\Models\User;
use Illuminate\Support\Facades\Gate;

class PatientShareController extends Controller
{
    public function searchDoctors(Request $request)
    {
        $query = $request->get('q', '');

        $doctors = User::role('doctor')
            ->where('id', '!=', auth()->id())
            ->when($query, function($q) use ($query) {
                $q->where(function($q2) use ($query) {
                    $q2->where('name', 'like', "%{$query}%")
                       ->orWhere('email', 'like', "%{$query}%")
                       ->orWhere('specialization', 'like', "%{$query}%");
                });
            })
            ->where('status', 'active')
            ->take(10)
            ->get(['id', 'name', 'email', 'specialization', 'code']);

        return response()->json($doctors);
    }

    public function index(Request $request, string $patientUuid)
    {
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        Gate::authorize('view', $patient);

        $shares = PatientShare::with('doctor:id,name,email,specialization,code')
            ->where('patient_id', $patient->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($shares);
    }

    public function store(Request $request, string $patientUuid)
    {
        if (config('database.default') === 'sqlite') {
            throw new \App\Exceptions\OfflineWriteNotAllowedException();
        }

        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        Gate::authorize('share', $patient);

        $validated = $request->validate([
            'doctor_id' => 'required|exists:users,id',
            'access_level' => 'required|in:read,read_write',
            'expires_at' => 'nullable|date|after:today'
        ]);

        $doctor = User::findOrFail($validated['doctor_id']);
        if (!$doctor->hasRole('doctor')) {
            return response()->json(['message' => 'Target user is not a doctor.'], 422);
        }

        $share = PatientShare::updateOrCreate(
            [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
            ],
            [
                'shared_by_id' => auth()->id(),
                'access_level' => $validated['access_level'],
                'expires_at' => collect($validated)->get('expires_at')
            ]
        );

        return response()->json($share->load('doctor:id,name,email,specialization,code'));
    }

    public function destroy(Request $request, string $patientUuid, string $shareId)
    {
        if (config('database.default') === 'sqlite') {
            throw new \App\Exceptions\OfflineWriteNotAllowedException();
        }

        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        Gate::authorize('share', $patient);

        $share = PatientShare::where('patient_id', $patient->id)
            ->where('id', $shareId)
            ->firstOrFail();

        $share->delete();

        return response()->json(['message' => 'Access revoked']);
    }
}
