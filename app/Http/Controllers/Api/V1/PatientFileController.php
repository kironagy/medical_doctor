<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientFileResource;
use App\Jobs\MergeChunksJob;
use App\Models\Patient;
use App\Models\PatientFile;
use App\Services\ChunkUploadService;
use App\Services\VideoUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PatientFileController extends Controller
{
    protected VideoUploadService $videoUploadService;
    protected ChunkUploadService $chunkUploadService;

    public function __construct(VideoUploadService $videoUploadService, ChunkUploadService $chunkUploadService)
    {
        $this->videoUploadService = $videoUploadService;
        $this->chunkUploadService = $chunkUploadService;
    }

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

        if ($request->boolean('initialize_upload')) {
            $file = $this->videoUploadService->initialize($patient, $data);
            return (new PatientFileResource($file))->response()->setStatusCode(201);
        }

        $data['patient_id'] = $patient->id;
        $data['uuid'] = $request->input('uuid') ?: Str::uuid()->toString();

        if ($request->hasFile('file')) {
            $uploadedFile = $request->file('file');
            $data['file_name'] = $uploadedFile->getClientOriginalName();
            $data['file_path'] = '/storage/'.$uploadedFile->store('patient_files', 'public');
            $data['data'] = null;
            $data['upload_status'] = 'ready';
        } else {
            $data['file_name'] = $data['file_name'] ?? 'ملاحظة_نصية.txt';
            $data['upload_status'] = 'ready';
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

        $this->chunkUploadService->storeChunk($uuid, $chunkIndex, $request->file('file'));

        if ($chunkIndex == $totalChunks - 1) {
            $extension = pathinfo($fileName, PATHINFO_EXTENSION) ?: 'bin';
            MergeChunksJob::dispatch($uuid, $totalChunks, $extension);

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
            'file_path' => $file->file_path,
            'thumbnail_path' => $file->thumbnail_path,
            'duration' => $file->duration,
            'resolution' => $file->resolution,
            'processing_progress' => $file->processing_progress,
            'processing_stage' => $file->processing_stage,
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
