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

        // Detect audio codec of the incoming source
        $ffprobeCmd = "ffprobe -v error -select_streams a:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($inputFile);
        $audioCodec = trim((string) shell_exec($ffprobeCmd));
        Log::info("HLSGenerator: probed audio codec", ['codec' => $audioCodec]);

        $audioOptions = "";
        if (strtolower($audioCodec) === 'aac') {
            Log::info("HLSGenerator: Source audio is AAC. Copying stream without re-encoding to preserve 100% quality.");
            $audioOptions = "-c:a copy";
        } else {
            Log::info("HLSGenerator: Source audio is not AAC. Re-encoding using high-fidelity parameters.");
            $audioOptions = "-c:a aac -b:a 192k -ar 48000 -ac 2";
        }

        // Generate only ONE HLS stream
        $cmd = "ffmpeg -i " . escapeshellarg($inputFile) . " " .
               "-codec:v libx264 -crf 28 -preset ultrafast " .
               $audioOptions . " " .
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
