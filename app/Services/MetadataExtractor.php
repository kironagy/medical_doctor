<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class MetadataExtractor
{
    public function extract(string $inputFile): array
    {
        Log::info("MetadataExtractor: extracting metadata from video", ['input' => $inputFile]);

        $cmdDuration = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($inputFile);
        $duration = shell_exec($cmdDuration);

        $cmdWidth = "ffprobe -v error -select_streams v:0 -show_entries stream=width -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($inputFile);
        $cmdHeight = "ffprobe -v error -select_streams v:0 -show_entries stream=height -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($inputFile);

        $width = shell_exec($cmdWidth);
        $height = shell_exec($cmdHeight);

        $duration = $duration ? (float) trim($duration) : 0.0;
        $width = $width ? (int) trim($width) : null;
        $height = $height ? (int) trim($height) : null;

        $resolution = ($width && $height) ? "{$width}x{$height}" : null;

        Log::info("MetadataExtractor: extraction completed", [
            'duration' => $duration,
            'resolution' => $resolution,
        ]);

        return [
            'duration' => $duration,
            'resolution' => $resolution,
        ];
    }
}
