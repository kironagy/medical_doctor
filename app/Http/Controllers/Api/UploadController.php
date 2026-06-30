<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Domains\Media\Services\UploadService;
use App\Domains\Patients\Models\Patient;
use App\Domains\Media\Jobs\OptimizeVideoJob;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    public function __construct(private readonly UploadService $uploadService) {}

    public function init(Request $request)
    {
        return response()->json([
            'session_id' => (string) Str::uuid()
        ]);
    }

    public function chunk(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string',
            'chunk' => 'required|file',
            'chunk_index' => 'required|integer',
        ]);

        $this->uploadService->storeChunk(
            $request->session_id, 
            $request->file('chunk'), 
            $request->chunk_index
        );

        return response()->json(['status' => 'chunk_received']);
    }

    public function complete(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string',
            'total_chunks' => 'required|integer',
            'patient_uuid' => 'required|string|exists:patients,uuid',
            'metadata' => 'required|array',
        ]);

        $patient = Patient::where('uuid', $request->patient_uuid)->firstOrFail();
        
        // Enforce Permissions (Primary Doctor or Read & Write Share)
        if ($request->user()->cannot('update', $patient)) {
            return response()->json(['message' => 'You do not have permission to upload files for this patient.'], 403);
        }

        $patientFile = $this->uploadService->mergeChunks(
            $request->session_id,
            $request->total_chunks,
            $patient,
            $request->metadata,
            $request->user()->id
        );

        if ($patientFile->type === 'video') {
            // Dispatch the video processing pipeline
            OptimizeVideoJob::dispatch($patientFile)->onQueue('video');
        } else {
            // If it's an image, dispatch to preview queue. Otherwise just mark ready.
            if ($patientFile->type === 'image') {
                // For now just mark ready, or dispatch GeneratePreviewJob if we had one.
                $patientFile->update(['upload_status' => 'ready']);
            } else {
                $patientFile->update(['upload_status' => 'ready']);
            }
        }

        return response()->json([
            'status' => 'success',
            'file' => [
                'uuid' => $patientFile->uuid,
                'status' => $patientFile->upload_status
            ]
        ]);
    }
}
