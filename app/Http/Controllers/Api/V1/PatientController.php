<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientResource;
use App\Models\Patient;
use App\Models\PatientFile;
use App\Models\PatientVisit;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);
        $search = trim((string) $request->query('search', $request->query('q', '')));
        $sort = $request->query('sort', '-id');
        $sortColumn = ltrim($sort, '-');
        $sortDirection = str_starts_with($sort, '-') ? 'desc' : 'asc';

        if (!in_array($sortColumn, ['id', 'code', 'name', 'phone', 'created_at', 'updated_at'], true)) {
            $sortColumn = 'id';
        }

        $patients = Patient::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('diagnosis', 'like', "%{$search}%");
                });
            })
            ->orderBy($sortColumn, $sortDirection)
            ->paginate($perPage);

        return PatientResource::collection($patients)->additional([
            'stats' => [
                'totalPatients' => Patient::count(),
                'totalFiles' => PatientFile::count(),
                'recentPatients' => Patient::where('created_at', '>=', now()->subDays(7))->count(),
                'monthlyIncome' => (float) PatientVisit::whereMonth('visit_date', now()->month)
                    ->whereYear('visit_date', now()->year)
                    ->sum('cost'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['code'] = $data['code'] ?? $this->generatePatientCode();

        $patient = Patient::create($data);

        return (new PatientResource($patient))->response()->setStatusCode(201);
    }

    public function show(Patient $patient)
    {
        return new PatientResource($patient->load(['files', 'visits']));
    }

    public function update(Request $request, Patient $patient)
    {
        $patient->update($this->validated($request, $patient));

        return new PatientResource($patient->refresh());
    }

    public function destroy(Patient $patient)
    {
        $patient->files()->delete();
        $patient->visits()->delete();
        $patient->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }

    private function validated(Request $request, ?Patient $patient = null): array
    {
        return $request->validate([
            'uuid' => ['nullable', 'uuid'],
            'code' => ['nullable', 'string', 'size:6', 'unique:patients,code,' . ($patient?->id ?? 'NULL')],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'diagnosis' => ['nullable', 'string'],
            'client_updated_at' => ['nullable', 'date'],
        ]);
    }

    private function generatePatientCode(): string
    {
        do {
            $code = str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (Patient::where('code', $code)->exists());

        return $code;
    }
}
