<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\VisitRepository;
use Illuminate\Http\Request;

class VisitController extends Controller
{
    public function __construct(
        private readonly VisitRepository $visits
    ) {}

    public function store(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'visit_type' => 'required|string|max:255',
            'reason' => 'nullable|string|max:1000',
            'visit_date' => 'nullable|date',
            'visit_time' => 'nullable|string|max:255',
            'diagnosis' => 'nullable|string|max:1000',
            'prescription' => 'nullable|string|max:1000',
            'cost' => 'nullable|numeric|min:0',
        ]);

        try {
            $this->visits->create($uuid, $validated);
            return redirect()->route('mobile.patients.show', $uuid)
                ->with('success', 'Visit added successfully.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function destroy(string $uuid, string $visitId)
    {
        try {
            $this->visits->delete($uuid, $visitId);
            return redirect()->route('mobile.patients.show', $uuid)
                ->with('success', 'Visit deleted.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }
}
