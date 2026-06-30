<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domains\Media\Models\PatientFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FileAccessController extends Controller
{
    /**
     * Generate a signed URL for an authorized user to access a file
     */
    public function generateSignedUrl(Request $request, string $uuid)
    {
        // Global scope ensures that if they don't have access to the patient, it throws 404
        $file = PatientFile::where('uuid', $uuid)->firstOrFail();
        
        $url = URL::temporarySignedRoute(
            'files.stream', now()->addHours(6), ['uuid' => $file->uuid]
        );

        return response()->json(['url' => $url]);
    }

    /**
     * Stream the actual file. Protected by Signed URL middleware.
     */
    public function streamDirect(string $uuid): BinaryFileResponse
    {
        $file = PatientFile::where('uuid', $uuid)->firstOrFail();
        
        $path = $file->file_path;
        if (!Storage::disk('local')->exists($path)) {
            abort(404, 'File not found on disk.');
        }

        $absolutePath = Storage::disk('local')->path($path);

        return response()->file($absolutePath, [
            'Content-Type' => mime_content_type($absolutePath),
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => 'inline; filename="' . $file->file_name . '"'
        ]);
    }

    public function thumbnailDirect(string $uuid): BinaryFileResponse
    {
        $file = PatientFile::where('uuid', $uuid)->firstOrFail();
        
        $path = $file->thumbnail_path;
        if (!$path || !Storage::disk('local')->exists($path)) {
            abort(404, 'Thumbnail not found.');
        }

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/jpeg'
        ]);
    }

    public function update(Request $request, string $uuid)
    {
        $file = PatientFile::where('uuid', $uuid)->firstOrFail();
        
        if ($request->user()->cannot('update', $file->patient)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'desc' => 'sometimes|string|nullable',
            'file_name' => 'sometimes|string|max:255',
            'category' => 'sometimes|string|max:255',
        ]);
        
        $file->update($validated);
        
        return response()->json($file);
    }

    public function destroy(Request $request, string $uuid)
    {
        $file = PatientFile::where('uuid', $uuid)->firstOrFail();
        
        if ($request->user()->cannot('delete', $file->patient)) {
            return response()->json(['message' => 'Unauthorized. Only primary doctor can delete files.'], 403);
        }

        $file->delete(); // Soft delete
        return response()->json(['message' => 'Deleted']);
    }
}
