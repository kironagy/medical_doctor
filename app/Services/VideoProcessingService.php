<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VideoProcessingService
{
    public function generateHls(string $inputFile, string $outputFolder): bool
    {
        if (!file_exists($outputFolder)) {
            mkdir($outputFolder, 0777, true);
        }

        // Multi-resolution encoding (360p, 480p, 720p)
        $cmd = "ffmpeg -i " . escapeshellarg($inputFile) . " " .
               "-filter_complex \"[0:v]split=3[v1][v2][v3];[v1]scale=-2:360[v1out];[v2]scale=-2:480[v2out];[v3]scale=-2:720[v3out]\" " .
               "-map \"[v1out]\" -c:v:0 libx264 -crf 28 -preset ultrafast -g 48 -sc_threshold 0 " .
               "-map \"[v2out]\" -c:v:1 libx264 -crf 28 -preset ultrafast -g 48 -sc_threshold 0 " .
               "-map \"[v3out]\" -c:v:2 libx264 -crf 28 -preset ultrafast -g 48 -sc_threshold 0 " .
               "-map 0:a -c:a:0 aac -b:a:0 96k " .
               "-map 0:a -c:a:1 aac -b:a:1 128k " .
               "-map 0:a -c:a:2 aac -b:a:2 128k " .
               "-f hls " .
               "-hls_time 4 " .
               "-hls_playlist_type vod " .
               "-hls_segment_filename " . escapeshellarg($outputFolder . "/seg_%v_%03d.ts") . " " .
               "-master_pl_name master.m3u8 " .
               "-var_stream_map \"v:0,a:0 v:1,a:1 v:2,a:2\" " .
               escapeshellarg($outputFolder . "/play_%v.m3u8") . " 2>&1";

        Log::info("VideoProcessingService: transcoding HLS", ['command' => $cmd]);
        shell_exec($cmd);

        return file_exists($outputFolder . '/master.m3u8');
    }

    public function generateThumbnail(string $inputFile, string $outputPath): bool
    {
        $cmd = "ffmpeg -i " . escapeshellarg($inputFile) . " -ss 00:00:02.000 -vframes 1 -vf \"scale=-2:480\" -y " . escapeshellarg($outputPath) . " 2>&1";
        shell_exec($cmd);

        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            $cmd = "ffmpeg -i " . escapeshellarg($inputFile) . " -ss 00:00:00.000 -vframes 1 -vf \"scale=-2:480\" -y " . escapeshellarg($outputPath) . " 2>&1";
            shell_exec($cmd);
        }

        return file_exists($outputPath) && filesize($outputPath) > 0;
    }

    public function generatePreviewGif(string $inputFile, string $outputPath): bool
    {
        // Generate a 3-second animated GIF preview from the 2nd second
        $cmd = "ffmpeg -i " . escapeshellarg($inputFile) . " -ss 00:00:02.000 -t 3 -vf \"fps=10,scale=320:-1:flags=lanczos,split[s0][s1];[s0]palettegen[p];[s1][p]paletteuse\" -y " . escapeshellarg($outputPath) . " 2>&1";
        shell_exec($cmd);

        return file_exists($outputPath) && filesize($outputPath) > 0;
    }
}
