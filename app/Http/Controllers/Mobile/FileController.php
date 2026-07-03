<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\FileRepository;
use Illuminate\Http\Request;

class FileController extends Controller
{
    public function __construct(
        private readonly FileRepository $files
    ) {}

    public function store(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'file' => 'required|file|max:512000',
            'title' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:100',
        ]);

        try {
            $uploadedFile = $request->file('file');
            $this->files->upload($uuid, [
                'file' => $uploadedFile,
            ], [
                'title' => $validated['title'] ?? $uploadedFile->getClientOriginalName(),
                'category' => $validated['category'] ?? '',
            ]);

            return redirect()->route('mobile.patients.show', $uuid)
                ->with('success', 'File uploaded successfully.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function download(string $fileUuid)
    {
        try {
            $file = $this->files->find($fileUuid);
            $fileData = $file['data'] ?? $file;
            $fileName = $fileData['file_name'] ?? 'file';

            $path = $this->files->download($fileUuid, $fileName);

            if ($path && file_exists($path)) {
                return response()->download($path, $fileName);
            }

            return redirect()->back()->withErrors(['error' => 'File not found.']);
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function destroy(string $fileUuid)
    {
        try {
            $file = $this->files->find($fileUuid);
            $fileData = $file['data'] ?? $file;
            $patientUuid = $fileData['patient_uuid'] ?? $fileData['patient_id'] ?? null;

            $this->files->delete($fileUuid);

            $redirectRoute = $patientUuid
                ? route('mobile.patients.show', $patientUuid)
                : route('mobile.patients.index');

            return redirect($redirectRoute)->with('success', 'File deleted.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }
}
