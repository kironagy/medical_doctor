<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ThumbnailGenerator
{
    public function generate(string $inputFile, string $outputPath): bool
    {
        Log::info("ThumbnailGenerator: starting thumbnail extraction", ['input' => $inputFile, 'output' => $outputPath]);
        
        $cmd = "ffmpeg -i " . escapeshellarg($inputFile) . " -ss 00:00:03.000 -vframes 1 -vf \"scale=-2:480\" -y " . escapeshellarg($outputPath) . " 2>&1";
        $output = shell_exec($cmd);
        
        Log::info("ThumbnailGenerator: primary attempt ffmpeg output", ['output' => $output]);

        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            Log::info("ThumbnailGenerator: primary failed, trying fallback to 00:00:00.000");
            $cmd = "ffmpeg -i " . escapeshellarg($inputFile) . " -ss 00:00:00.000 -vframes 1 -vf \"scale=-2:480\" -y " . escapeshellarg($outputPath) . " 2>&1";
            shell_exec($cmd);
        }

        $success = file_exists($outputPath) && filesize($outputPath) > 0;
        Log::info("ThumbnailGenerator: extraction completed", ['success' => $success]);
        return $success;
    }

    public function generatePreview(string $inputFile, string $outputPath): bool
    {
        Log::info("ThumbnailGenerator: starting preview GIF extraction", ['input' => $inputFile, 'output' => $outputPath]);
        
        $cmd = "ffmpeg -i " . escapeshellarg($inputFile) . " -ss 00:00:02.000 -t 3 -vf \"fps=10,scale=320:-1:flags=lanczos,split[s0][s1];[s0]palettegen[p];[s1][p]paletteuse\" -y " . escapeshellarg($outputPath) . " 2>&1";
        $output = shell_exec($cmd);
        
        Log::info("ThumbnailGenerator: preview GIF ffmpeg output", ['output' => $output]);

        $success = file_exists($outputPath) && filesize($outputPath) > 0;
        Log::info("ThumbnailGenerator: preview GIF generation completed", ['success' => $success]);
        return $success;
    }
}
