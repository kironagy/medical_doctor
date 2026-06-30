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
    /**
     * Search doctors to share with
     */
    public function searchDoctors(Request $request)
    {
        $query = $request->get('q', '');
        
        $doctors = User::role('doctor')
            ->where('id', '!=', auth()->id()) // Exclude self
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

    /**
     * Get active shares for a patient
     */
    public function index(Patient $patient)
    {
        Gate::authorize('view', $patient);
        
        $shares = PatientShare::with('doctor:id,name,email,specialization,code')
            ->where('patient_id', $patient->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($shares);
    }

    /**
     * Share patient with a doctor
     */
    public function store(Request $request, Patient $patient)
    {
        Gate::authorize('share', $patient);

        $validated = $request->validate([
            'doctor_id' => 'required|exists:users,id',
            'access_level' => 'required|in:read,read_write',
            'expires_at' => 'nullable|date|after:today'
        ]);

        // Validate doctor role
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

    /**
     * Revoke access
     */
    public function destroy(Patient $patient, string $shareId)
    {
        Gate::authorize('share', $patient);

        $share = PatientShare::where('patient_id', $patient->id)
            ->where('id', $shareId)
            ->firstOrFail();
            
        $share->delete();

        return response()->json(['message' => 'Access revoked']);
    }
}
