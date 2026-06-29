<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class HLSGenerator
{
    public function generate(string $inputFile, string $outputFolder): bool
    {
        Log::info("HLSGenerator: starting HLS stream generation", [
            'original_mp4_path' => $inputFile,
            'hls_output_folder' => $outputFolder
        ]);

        if (!file_exists($outputFolder)) {
            mkdir($outputFolder, 0777, true);
        }

        // Generate only ONE HLS stream
        $cmd = "ffmpeg -i " . escapeshellarg($inputFile) . " " .
               "-codec:v libx264 -crf 28 -preset ultrafast " .
               "-codec:a aac -b:a 128k " .
               "-f hls " .
               "-hls_time 4 " .
               "-hls_playlist_type vod " .
               "-hls_segment_filename " . escapeshellarg($outputFolder . "/seg_%03d.ts") . " " .
               escapeshellarg($outputFolder . "/video.m3u8") . " 2>&1";

        Log::info("HLSGenerator: running FFmpeg command", ['command' => $cmd]);
        exec($cmd, $output, $exitCode);
        Log::info("HLSGenerator: FFmpeg completed", [
            'exit_code' => $exitCode,
            'output' => implode("\n", $output)
        ]);

        $success = file_exists($outputFolder . '/video.m3u8');
        Log::info("HLSGenerator: transcoding completed", ['success' => $success]);
        return $success;
    }
}
