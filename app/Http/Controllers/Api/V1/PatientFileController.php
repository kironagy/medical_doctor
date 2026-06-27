<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientFileResource;
use App\Models\Patient;
use App\Models\PatientFile;
use Illuminate\Http\Request;

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
            'file' => ['nullable', 'file'], // Removed max rule to allow chunks
            'file_name' => ['nullable', 'string', 'max:255'],
            'file_path' => ['nullable', 'string', 'max:500'],
            'data' => ['nullable', 'string'],
            'client_updated_at' => ['nullable', 'date'],
            'chunk_index' => ['nullable', 'integer'],
            'total_chunks' => ['nullable', 'integer'],
        ]);

        $data['patient_id'] = $patient->id;

        if ($request->has('chunk_index') && $request->hasFile('file')) {
            $chunkIndex = $request->integer('chunk_index');
            $totalChunks = $request->integer('total_chunks');
            $uuid = $request->input('uuid') ?: \Illuminate\Support\Str::uuid()->toString();
            $data['uuid'] = $uuid;

            if (empty($data['file_name'])) {
                $data['file_name'] = $request->file('file')->getClientOriginalName();
            }

            $tempDir = 'chunks/' . $uuid;
            \Illuminate\Support\Facades\Storage::disk('local')->putFileAs($tempDir, $request->file('file'), $chunkIndex . '.part');

            if ($chunkIndex == $totalChunks - 1) {
                $extension = pathinfo($data['file_name'], PATHINFO_EXTENSION);
                if (empty($extension)) $extension = 'bin';

                $finalName = \Illuminate\Support\Str::random(40) . '.' . $extension;
                $finalPath = storage_path('app/public/patient_files/' . $finalName);

                if (!file_exists(storage_path('app/public/patient_files'))) {
                    mkdir(storage_path('app/public/patient_files'), 0777, true);
                }

                $finalFile = fopen($finalPath, 'ab');
                for ($i = 0; $i < $totalChunks; $i++) {
                    $partPath = \Illuminate\Support\Facades\Storage::disk('local')->path($tempDir . '/' . $i . '.part');
                    if (file_exists($partPath)) {
                        $chunkFile = fopen($partPath, 'rb');
                        stream_copy_to_stream($chunkFile, $finalFile);
                        fclose($chunkFile);
                    }
                }
                fclose($finalFile);
                \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory($tempDir);

                $data['file_path'] = '/storage/patient_files/' . $finalName;
                $data['data'] = null;
            } else {
                return response()->json(['message' => 'Chunk received', 'uuid' => $uuid], 200);
            }
        } elseif ($request->hasFile('file')) {
            $uploadedFile = $request->file('file');
            $data['file_name'] = $uploadedFile->getClientOriginalName();
            $data['file_path'] = '/storage/'.$uploadedFile->store('patient_files', 'public');
            $data['data'] = null;
        } else {
            $data['file_name'] = $data['file_name'] ?? 'ملاحظة_نصية.txt';
        }

        $file = PatientFile::create($data);

        return (new PatientFileResource($file))->response()->setStatusCode(201);
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
