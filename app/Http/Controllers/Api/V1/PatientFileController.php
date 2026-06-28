<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientFileResource;
use App\Jobs\MergeVideoChunksJob;
use App\Models\Patient;
use App\Models\PatientFile;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class PatientFileController extends Controller
{
    public function index(Request $request, Patient $patient)
    {
        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);
        $search = trim((string) $request->query('search', $request->query('q', '')));

        $files = $patient->files()
            ->when($request->filled('category') && $request->query('category') !== 'all', fn ($query) => $query->where('category', $request->query('category')))
            ->when($request->filled('type') && $request->query('type') !== 'all', fn ($query) => $query->where('type', 'like', '%'.$request->query('type').'%'))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('desc', 'like', "%{$search}%")
                        ->orWhere('file_name', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->paginate($perPage);

        return PatientFileResource::collection($files);
    }

    public function store(Request $request, Patient $patient)
    {
        $data = $request->validate([
            'uuid' => ['nullable', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'desc' => ['nullable', 'string'],
            'type' => ['required', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'file' => ['nullable', 'file'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'file_path' => ['nullable', 'string', 'max:500'],
            'data' => ['nullable', 'string'],
            'client_updated_at' => ['nullable', 'date'],
            'initialize_upload' => ['nullable', 'boolean'],
        ]);

        $data['patient_id'] = $patient->id;
        $data['uuid'] = $request->input('uuid') ?: Str::uuid()->toString();

        if ($request->boolean('initialize_upload')) {
            $data['upload_status'] = 'uploading';
            if (empty($data['file_name'])) {
                $data['file_name'] = 'uploading...' . ($data['type'] === 'video' ? '.mp4' : '');
            }
            $file = PatientFile::create($data);
            return (new PatientFileResource($file))->response()->setStatusCode(201);
        }

        if ($request->hasFile('file')) {
            $uploadedFile = $request->file('file');
            $data['file_name'] = $uploadedFile->getClientOriginalName();
            $data['file_path'] = '/storage/'.$uploadedFile->store('patient_files', 'public');
            $data['data'] = null;
            $data['upload_status'] = 'completed';
        } else {
            $data['file_name'] = $data['file_name'] ?? 'ملاحظة_نصية.txt';
            $data['upload_status'] = 'completed';
        }

        $file = PatientFile::create($data);

        return (new PatientFileResource($file))->response()->setStatusCode(201);
    }

    public function uploadChunk(Request $request)
    {
        $request->validate([
            'uuid' => ['required', 'uuid'],
            'chunk_index' => ['required', 'integer'],
            'total_chunks' => ['required', 'integer'],
            'file' => ['required', 'file'],
            'file_name' => ['required', 'string'],
        ]);

        $uuid = $request->input('uuid');
        $chunkIndex = $request->integer('chunk_index');
        $totalChunks = $request->integer('total_chunks');
        $fileName = $request->input('file_name');

        $tempDir = 'chunks/' . $uuid;
        Storage::disk('local')->putFileAs($tempDir, $request->file('file'), $chunkIndex . '.part');

        if ($chunkIndex == $totalChunks - 1) {
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            if (empty($extension)) $extension = 'bin';

            MergeVideoChunksJob::dispatch($uuid, $totalChunks, $extension);

            return response()->json(['message' => 'Chunk received, merging started', 'uuid' => $uuid], 202);
        }

        return response()->json(['message' => 'Chunk received', 'uuid' => $uuid], 200);
    }

    public function uploadStatus($uuid)
    {
        $file = PatientFile::where('uuid', $uuid)->firstOrFail();
        return response()->json([
            'uuid' => $file->uuid,
            'upload_status' => $file->upload_status,
            'file_path' => $file->file_path
        ]);
    }

    public function show(Patient $patient, PatientFile $file)
    {
        abort_unless((int) $file->patient_id === (int) $patient->id, 404);

        return new PatientFileResource($file);
    }

    public function destroy(Patient $patient, PatientFile $file)
    {
        abort_unless((int) $file->patient_id === (int) $patient->id, 404);
        $file->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }
}
