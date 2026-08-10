<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreFileRequest;
use App\Http\Controllers\Controller;
use App\Domains\Media\Services\UploadService;
use App\Domains\Patients\Models\Patient;
use App\Domains\Media\Resources\FileResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class UploadController extends Controller
{
    public function __construct(private readonly UploadService $uploadService) {}

    public function store(StoreFileRequest $request, string $patientUuid)
    {
        if (config('database.default') === 'sqlite') {
            throw new \App\Exceptions\OfflineWriteNotAllowedException();
        }

        $validated = $request->validated();

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

        } catch (\Throwable $e) {
            $sqlState = null;
            $sqliteError = null;
            if ($e instanceof \PDOException) {
                $sqlState = $e->errorInfo[0] ?? $e->getCode();
                $sqliteError = $e->errorInfo[2] ?? $e->getMessage();
            } elseif ($e->getPrevious() instanceof \PDOException) {
                $pdoEx = $e->getPrevious();
                $sqlState = $pdoEx->errorInfo[0] ?? $pdoEx->getCode();
                $sqliteError = $pdoEx->errorInfo[2] ?? $pdoEx->getMessage();
            }

            Log::error('UPLOAD EXCEPTION (UploadController@store)', [
                'patient_id'   => $patient->id ?? null,
                'patient_uuid' => $patientUuid,
                'user_id'      => auth()->id(),
                'exception'    => get_class($e),
                'message'      => $e->getMessage(),
                'sqlstate'     => $sqlState,
                'sqlite_error' => $sqliteError,
                'file'         => $e->getFile(),
                'line'         => $e->getLine(),
                'trace'        => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success'      => false,
                'message'      => 'Upload failed: ' . $e->getMessage(),
                'exception'    => get_class($e),
                'sqlstate'     => $sqlState,
                'sqlite_error' => $sqliteError,
                'file'         => $e->getFile(),
                'line'         => $e->getLine(),
                'trace'        => $e->getTraceAsString(),
            ], 500);
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
