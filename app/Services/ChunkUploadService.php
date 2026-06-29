<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ChunkUploadService
{
    public function storeChunk(string $uuid, int $chunkIndex, UploadedFile $file): string
    {
        $tempDir = 'chunks/' . $uuid;
        return Storage::disk('local')->putFileAs($tempDir, $file, $chunkIndex . '.part');
    }
}
