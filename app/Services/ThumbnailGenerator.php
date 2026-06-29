<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ThumbnailGenerator
{
    public function generate(string $inputFile, string $outputPath): bool
    {
        Log::info("ThumbnailGenerator: starting thumbnail extraction", [
            'original_mp4_path' => $inputFile,
            'thumbnail_output_path' => $outputPath
        ]);
        
        $cmd = "ffmpeg -i " . escapeshellarg($inputFile) . " -ss 00:00:03.000 -vframes 1 -vf \"scale=-2:480\" -y " . escapeshellarg($outputPath) . " 2>&1";
        
        Log::info("ThumbnailGenerator: running FFmpeg command", ['command' => $cmd]);
        exec($cmd, $output, $exitCode);
        Log::info("ThumbnailGenerator: FFmpeg completed", [
            'exit_code' => $exitCode,
            'output' => implode("\n", $output)
        ]);

        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            Log::info("ThumbnailGenerator: primary failed, trying fallback to 00:00:00.000");
            $cmd = "ffmpeg -i " . escapeshellarg($inputFile) . " -ss 00:00:00.000 -vframes 1 -vf \"scale=-2:480\" -y " . escapeshellarg($outputPath) . " 2>&1";
            exec($cmd, $outputFallback, $exitCodeFallback);
            Log::info("ThumbnailGenerator: Fallback FFmpeg completed", [
                'exit_code' => $exitCodeFallback,
                'output' => implode("\n", $outputFallback)
            ]);
        }

        $success = file_exists($outputPath) && filesize($outputPath) > 0;
        Log::info("ThumbnailGenerator: extraction completed", ['success' => $success]);
        return $success;
    }

    public function generatePreview(string $inputFile, string $outputPath): bool
    {
        Log::info("ThumbnailGenerator: starting preview GIF extraction", [
            'original_mp4_path' => $inputFile,
            'preview_output_path' => $outputPath
        ]);
        
        $cmd = "ffmpeg -i " . escapeshellarg($inputFile) . " -ss 00:00:02.000 -t 3 -vf \"fps=10,scale=320:-1:flags=lanczos,split[s0][s1];[s0]palettegen[p];[s1][p]paletteuse\" -y " . escapeshellarg($outputPath) . " 2>&1";
        
        Log::info("ThumbnailGenerator: running FFmpeg command", ['command' => $cmd]);
        exec($cmd, $output, $exitCode);
        Log::info("ThumbnailGenerator: FFmpeg completed", [
            'exit_code' => $exitCode,
            'output' => implode("\n", $output)
        ]);

        $success = file_exists($outputPath) && filesize($outputPath) > 0;
        Log::info("ThumbnailGenerator: preview GIF generation completed", ['success' => $success]);
        return $success;
    }
}
