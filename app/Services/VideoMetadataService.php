<?php

namespace App\Services;

class VideoMetadataService
{
    public function getDuration(string $inputFile): float
    {
        $cmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($inputFile);
        $duration = shell_exec($cmd);
        return $duration ? (float) trim($duration) : 0.0;
    }

    public function getDimensions(string $inputFile): array
    {
        $cmdWidth = "ffprobe -v error -select_streams v:0 -show_entries stream=width -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($inputFile);
        $cmdHeight = "ffprobe -v error -select_streams v:0 -show_entries stream=height -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($inputFile);

        $width = shell_exec($cmdWidth);
        $height = shell_exec($cmdHeight);

        return [
            'width' => $width ? (int) trim($width) : null,
            'height' => $height ? (int) trim($height) : null,
        ];
    }
}
