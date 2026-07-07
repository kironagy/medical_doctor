<?php

namespace App\Services\Upload;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UploadChecksumService
{
    public function chunkChecksum(UploadedFile $chunk): string
    {
        return hash_file('sha256', $chunk->getRealPath()) ?: '';
    }

    public function finalChecksum(string $disk, string $filePath): string
    {
        $absPath = Storage::disk($disk)->path($filePath);
        return hash_file('sha256', $absPath) ?: '';
    }

    public function verifyString(string $expected, string $actual): bool
    {
        return hash_equals($expected, $actual);
    }
}
