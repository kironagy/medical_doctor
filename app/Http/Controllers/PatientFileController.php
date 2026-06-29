<?php

namespace App\Http\Controllers;

use App\Models\PatientFile;
use App\Models\Patient;
use Illuminate\Http\Request;

class PatientFileController extends Controller
{
    public function index($patientId)
    {
        $files = PatientFile::where('patient_id', $patientId)->orderBy('id', 'desc')->get();
        return response()->json($files);
    }

    public function store(Request $request, $patientId)
    {
        $request->validate([
            'title' => 'required|string',
            'desc' => 'nullable|string',
            'type' => 'required|string',
            'category' => 'nullable|string',
            'date' => 'required|date',
            'file' => 'nullable|file|max:50000', // 50MB max, nullable for text notes
        ]);

        $fileData = $request->only(['title', 'desc', 'type', 'category', 'date']);
        $fileData['patient_id'] = $patientId;

        if ($request->hasFile('file')) {
            $uploadedFile = $request->file('file');
            $fileData['file_name'] = $uploadedFile->getClientOriginalName();
            $path = $uploadedFile->store('patient_files', 'public');
            $fileData['file_path'] = '/storage/' . $path;
            
            // Generate a placeholder based on file type for frontend compatibility if needed
            $fileData['data'] = null;
        } else {
            $fileData['file_name'] = 'ملاحظة_نصية.txt';
        }

        $file = PatientFile::create($fileData);

        return response()->json($file, 201);
    }

    public function destroy($patientId, $id)
    {
        $file = PatientFile::where('patient_id', $patientId)->findOrFail($id);
        $file->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }
}
