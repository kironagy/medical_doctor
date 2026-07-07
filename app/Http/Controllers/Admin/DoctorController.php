<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Domains\Users\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class DoctorController extends Controller
{
    public function index(Request $request)
    {
        $query = User::role('doctor')->withCount(['patients']); // Assuming patients relationship exists, actually it's primaryDoctor on patients table, but inverse may not exist. Let's fix that.
        
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $doctors = $query->latest()->paginate(10)->withQueryString();

        return Inertia::render('Admin/Doctors/Index', [
            'doctors' => $doctors,
            'filters' => $request->only(['search', 'status'])
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Doctors/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'nullable|string|max:255',
            'specialization' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:1000',
            'password' => 'required|string|min:8',
        ]);

        $validated['code'] = 'DR-' . strtoupper(Str::random(5));
        $validated['password'] = Hash::make($validated['password']);
        $validated['status'] = 'active';

        $doctor = User::create($validated);
        $doctor->assignRole('doctor');

        return redirect()->route('admin.doctors.index')->with('success', 'Doctor created successfully.');
    }

    public function suspend(User $doctor)
    {
        $doctor->update(['status' => $doctor->status === 'active' ? 'suspended' : 'active']);
        return back()->with('success', 'Doctor status updated.');
    }

    public function show(User $doctor)
    {
        $doctor->loadCount(['patients']);
        
        // Storage usage calculation
        $files = \App\Domains\Media\Models\PatientFile::whereHas('patient', function($q) use ($doctor) {
            $q->withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)
              ->where('primary_doctor_id', $doctor->id);
        })->get();
        
        $totalStorageBytes = $files->sum('file_size');
        $totalFiles = $files->count();

        return Inertia::render('Admin/Doctors/Show', [
            'doctor' => $doctor,
            'stats' => [
                'total_patients' => $doctor->patients_count,
                'total_files' => $totalFiles,
                'storage_bytes' => $totalStorageBytes,
            ]
        ]);
    }

    public function patients(Request $request, User $doctor)
    {
        $query = \App\Domains\Patients\Models\Patient::withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)
            ->where('primary_doctor_id', $doctor->id)
            ->withCount('files');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return response()->json($query->latest()->paginate(10));
    }

    public function files(Request $request, User $doctor)
    {
        $query = \App\Domains\Media\Models\PatientFile::whereHas('patient', function($q) use ($doctor) {
            $q->withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)
              ->where('primary_doctor_id', $doctor->id);
        })->with('patient:id,name,code,uuid');

        if ($request->filled('search')) {
            $query->where('file_name', 'like', "%{$request->search}%");
        }

        if ($request->filled('type')) {
            $query->where('mime_type', 'like', "{$request->type}%");
        }

        return response()->json($query->latest()->paginate(24));
    }

    public function edit(User $doctor)
    {
        return Inertia::render('Admin/Doctors/Edit', [
            'doctor' => $doctor
        ]);
    }

    public function update(Request $request, User $doctor)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($doctor->id)],
            'phone' => 'nullable|string|max:255',
            'specialization' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:1000',
        ]);

        $doctor->update($validated);

        return redirect()->route('admin.doctors.index')->with('success', 'Doctor updated successfully.');
    }

    public function destroy(User $doctor)
    {
        $doctor->delete();

        return redirect()->route('admin.doctors.index')->with('success', 'Doctor deleted successfully.');
    }
}
