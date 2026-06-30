<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Domains\Media\Services\UploadService;
use App\Domains\Patients\Models\Patient;
use App\Domains\Media\Resources\FileResource;
use Illuminate\Support\Facades\Log;
use Exception;

class UploadController extends Controller
{
    public function __construct(private readonly UploadService $uploadService) {}

    /**
     * Upload a file directly to a patient.
     * 
     * POST /api/v1/patients/{patient}/files
     */
    public function store(Request $request, Patient $patient)
    {
        $request->validate([
            'file' => 'required|file|max:512000', // 500MB max
            'title' => 'sometimes|string|max:255',
            'desc' => 'sometimes|string|max:1000',
            'category' => 'sometimes|string|max:100',
            'date' => 'sometimes|date',
        ]);

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

    /**
     * Get upload progress (for compatibility, but not needed with direct uploads).
     * 
     * GET /api/v1/uploads/progress
     */
    public function progress(Request $request)
    {
        // With direct uploads, this endpoint is not needed
        // But keeping for frontend compatibility during transition
        return response()->json([
            'progress' => 100,
            'status' => 'completed',
            'message' => 'Upload completed',
        ]);
    }
}