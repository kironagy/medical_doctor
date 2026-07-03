<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\NoteRepository;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    public function __construct(
        private readonly NoteRepository $notes
    ) {}

    public function store(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'content' => 'required|string',
            'category' => 'nullable|string|max:100',
        ]);

        try {
            $this->notes->create($uuid, $validated);
            return redirect()->route('mobile.patients.show', $uuid)
                ->with('success', 'Note added.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function update(Request $request, string $uuid, string $noteUuid)
    {
        $validated = $request->validate([
            'content' => 'required|string',
        ]);

        try {
            $this->notes->update($uuid, $noteUuid, $validated);
            return redirect()->route('mobile.patients.show', $uuid)
                ->with('success', 'Note updated.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function destroy(string $uuid, string $noteUuid)
    {
        try {
            $this->notes->delete($uuid, $noteUuid);
            return redirect()->route('mobile.patients.show', $uuid)
                ->with('success', 'Note deleted.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }
}
