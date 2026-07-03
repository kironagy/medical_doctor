<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Resources\PatientResource;
use App\Domains\ActivityLogs\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PatientController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger
    ) {}

    public function index(Request $request)
    {
        $perPage = min($request->integer('per_page', 20), 100);
        $user = $request->user();

        $query = Patient::query()
            ->with('primaryDoctor:id,name,email')
            ->orderBy('created_at', 'desc');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('diagnosis', 'like', "%{$search}%");
            });
        }

        $patients = $query->paginate($perPage);

        return response()->json($patients);
    }

    public function show(string $uuid)
    {
        $patient = Patient::with([
            'primaryDoctor:id,name,email',
            'visits' => fn($q) => $q->latest(),
            'files' => fn($q) => $q->latest(),
        ])->where('uuid', $uuid)->firstOrFail();

        Gate::authorize('view', $patient);

        return response()->json(new PatientResource($patient));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'diagnosis' => 'nullable|string|max:1000',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|string|max:255',
            'blood_group' => 'nullable|string|max:255',
            'weight' => 'nullable|numeric',
            'height' => 'nullable|numeric',
            'allergies' => 'nullable|string',
            'chronic_diseases' => 'nullable|string',
            'medical_status' => 'nullable|string|max:255',
            'medical_record_number' => 'nullable|string|max:100',
            'code' => 'nullable|string|max:255',
            'uuid' => 'nullable|uuid',
        ]);

        $validated['primary_doctor_id'] = $request->user()->id;
        $validated['created_by_id'] = $request->user()->id;

        $patient = Patient::create($validated);

        $this->logger->log('patient_created', 'Patient', $patient->uuid, [
            'patient_name' => $patient->name,
        ]);

        return response()->json(new PatientResource($patient), 201);
    }

    public function update(Request $request, string $uuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('update', $patient);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'diagnosis' => 'nullable|string|max:1000',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|string|max:255',
            'blood_group' => 'nullable|string|max:255',
            'weight' => 'nullable|numeric',
            'height' => 'nullable|numeric',
            'allergies' => 'nullable|string',
            'chronic_diseases' => 'nullable|string',
            'medical_status' => 'nullable|string|max:255',
            'medical_record_number' => 'nullable|string|max:100',
            'code' => 'nullable|string|max:255',
        ]);

        $patient->update($validated);

        $this->logger->log('patient_updated', 'Patient', $patient->uuid, [
            'patient_name' => $patient->name,
        ]);

        return response()->json(new PatientResource($patient->fresh()));
    }

    public function destroy(string $uuid)
    {
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('delete', $patient);

        $patient->delete();

        $this->logger->log('patient_deleted', 'Patient', $patient->uuid, [
            'patient_name' => $patient->name,
        ]);

        return response()->json(['message' => 'Patient deleted successfully']);
    }
}
