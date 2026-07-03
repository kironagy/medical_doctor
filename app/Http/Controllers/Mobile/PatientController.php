<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\PatientRepository;
use App\Services\Mobile\VisitRepository;
use App\Services\Mobile\NoteRepository;
use App\Services\Mobile\FileRepository;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    public function __construct(
        private readonly PatientRepository $patients,
        private readonly VisitRepository $visits,
        private readonly NoteRepository $notes,
        private readonly FileRepository $files,
    ) {}

    public function index(Request $request)
    {
        $page = $request->integer('page', 1);
        $search = $request->get('search');
        $result = $this->patients->all($page, 20, $search);

        return view('mobile.patients.index', [
            'patients' => $result['data'] ?? $result ?? [],
            'nextPageUrl' => $result['next_page_url'] ?? null,
            'prevPageUrl' => $result['prev_page_url'] ?? null,
            'currentPage' => $page,
            'search' => $search,
            'lastPage' => $result['last_page'] ?? 1,
            'pageTitle' => 'Patients',
        ]);
    }

    public function show(string $uuid)
    {
        $patient = $this->patients->find($uuid);
        $patientData = $patient['data'] ?? $patient;
        $visits = $this->visits->all($uuid);
        $notes = $this->notes->all($uuid);
        $files = $this->files->all($uuid);

        return view('mobile.patients.show', [
            'patient' => $patientData,
            'visits' => $visits['data'] ?? $visits ?? [],
            'notes' => $notes['data'] ?? $notes ?? [],
            'files' => $files['data'] ?? $files ?? [],
            'pageTitle' => $patientData['name'] ?? 'Patient',
        ]);
    }

    public function create()
    {
        return view('mobile.patients.form', [
            'patient' => null,
            'isEdit' => false,
            'pageTitle' => 'Add Patient',
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'diagnosis' => 'nullable|string|max:1000',
            'code' => 'nullable|string|max:255',
        ]);

        try {
            $this->patients->create($validated);
            return redirect()->route('mobile.patients.index')
                ->with('success', 'Patient created successfully.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }
    }

    public function edit(string $uuid)
    {
        $patient = $this->patients->find($uuid);
        return view('mobile.patients.form', [
            'patient' => $patient['data'] ?? $patient,
            'isEdit' => true,
            'pageTitle' => 'Edit Patient',
        ]);
    }

    public function update(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'diagnosis' => 'nullable|string|max:1000',
            'code' => 'nullable|string|max:255',
        ]);

        try {
            $this->patients->update($uuid, $validated);
            return redirect()->route('mobile.patients.show', $uuid)
                ->with('success', 'Patient updated successfully.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }
    }

    public function destroy(string $uuid)
    {
        try {
            $this->patients->delete($uuid);
            return redirect()->route('mobile.patients.index')
                ->with('success', 'Patient deleted successfully.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }
}
