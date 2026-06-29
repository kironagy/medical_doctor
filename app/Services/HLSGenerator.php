<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class HLSGenerator
{
    public function generate(string $inputFile, string $outputFolder): bool
    {
        Log::info("HLSGenerator: starting single optimized HLS stream generation", ['input' => $inputFile, 'output' => $outputFolder]);

        if (!file_exists($outputFolder)) {
            mkdir($outputFolder, 0777, true);
        }

        // Generate only ONE optimized HLS stream: video.m3u8 and seg_000.ts ...
        $cmd = "ffmpeg -i " . escapeshellarg($inputFile) . " " .
               "-codec:v libx264 -crf 28 -preset ultrafast " .
               "-codec:a aac -b:a 128k " .
               "-f hls " .
               "-hls_time 4 " .
               "-hls_playlist_type vod " .
               "-hls_segment_filename " . escapeshellarg($outputFolder . "/seg_%03d.ts") . " " .
               escapeshellarg($outputFolder . "/video.m3u8") . " 2>&1";

        Log::info("HLSGenerator: running command", ['command' => $cmd]);
        $output = shell_exec($cmd);
        Log::info("HLSGenerator: command output", ['output' => $output]);

        $success = file_exists($outputFolder . '/video.m3u8');
        Log::info("HLSGenerator: single stream transcoding completed", ['success' => $success]);
        return $success;
    }
}
