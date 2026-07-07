<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domains\Media\Services\UploadService;
use App\Models\Patient;
use App\Http\Resources\PatientFileResource as FileResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class UploadController extends Controller
{
    public function __construct(private readonly UploadService $uploadService) {}

    public function store(Request $request, string $patientUuid)
    {
        $request->validate([
            'file' => 'required|file|max:512000',
            'title' => 'sometimes|string|max:255',
            'desc' => 'sometimes|string|max:1000',
            'category' => 'sometimes|string|max:100',
            'date' => 'sometimes|date',
        ]);

        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();

        try {
            $patientFile = $this->uploadService->uploadFile(
                file: $request->file('file'),
                patientId: $patient->id,
                uploaderId: auth()->id(),
                metadata: $request->only(['title', 'desc', 'category', 'date'])
            );

            Log::info('File uploaded successfully', [
                'patient_id' => $patient->id,
                'file_id' => $patientFile->id,
                'file_name' => $patientFile->file_name,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'File uploaded successfully',
                'file' => new FileResource($patientFile),
            ], 201);

        } catch (Exception $e) {
            Log::error('File upload failed', [
                'patient_id' => $patient->id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    public function progress(Request $request)
    {
        return response()->json([
            'progress' => 100,
            'status' => 'completed',
            'message' => 'Upload completed',
        ]);
    }
}
