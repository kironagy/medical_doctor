<?php

namespace App\Domains\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Domains\Media\Models\PatientFile;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;

class OptimizeVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour max
    public $tries = 3;
    public $backoff = 60; // Wait 1 min before retrying
    
    public function __construct(private readonly PatientFile $patientFile) {}

    public function handle(): void
    {
        $t0 = microtime(true);
        $this->patientFile->update(['upload_status' => 'processing']);

        $inputPath = Storage::disk('local')->path($this->patientFile->file_path);
        if (!file_exists($inputPath)) {
            $this->fail(new Exception("File not found at path: {$inputPath}"));
            return;
        }

        // 1. FFPROBE METADATA
        $ffprobeCmd = [
            'ffprobe', '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $inputPath
        ];
        $process = new Process($ffprobeCmd);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->fail(new Exception("ffprobe failed: " . $process->getErrorOutput()));
            return;
        }

        $metadata = json_decode($process->getOutput(), true);
        $hasVideo = false;
        $codec = null;
        $width = null;
        $height = null;
        $duration = null;

        foreach ($metadata['streams'] ?? [] as $stream) {
            if (isset($stream['codec_type']) && $stream['codec_type'] === 'video') {
                $hasVideo = true;
                $codec = $stream['codec_name'] ?? null;
                $width = $stream['width'] ?? null;
                $height = $stream['height'] ?? null;
                break;
            }
        }
        $duration = isset($metadata['format']['duration']) ? (int) round((float) $metadata['format']['duration']) : null;

        // If not a video, mark completed immediately
        if (!$hasVideo) {
            $this->patientFile->update(['upload_status' => 'ready']);
            return;
        }

        $this->patientFile->update(['upload_status' => 'optimizing']);

        // 2. FASTSTART MP4 (progressive download fallback)
        $ext = pathinfo($inputPath, PATHINFO_EXTENSION);
        $outputPath = substr($inputPath, 0, -(strlen($ext) + 1)) . '_optimized.mp4';

        if (in_array($codec, ['h264', 'hevc'])) {
            // ZERO Re-encoding, pure disk copy
            $ffmpegCmd = [
                'ffmpeg', '-y', '-i', $inputPath,
                '-c', 'copy',
                '-movflags', '+faststart',
                $outputPath
            ];
            Log::channel('upload')->info("faststart (copy)", ['file' => $this->patientFile->uuid]);
        } else {
            // Transcode
            $ffmpegCmd = [
                'ffmpeg', '-y', '-i', $inputPath,
                '-c:v', 'libx264',
                '-preset', 'fast',
                '-c:a', 'aac',
                '-movflags', '+faststart',
                $outputPath
            ];
            Log::channel('upload')->info("faststart (transcode)", ['file' => $this->patientFile->uuid]);
        }

        $process = new Process($ffmpegCmd);
        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->patientFile->update(['upload_status' => 'failed']);
            $this->fail(new Exception("ffmpeg failed: " . $process->getErrorOutput()));
            return;
        }

        // Swap optimized mp4 in place of original
        $newRelativePath = substr($this->patientFile->file_path, 0, -(strlen($ext) + 1)) . '.mp4';
        $newAbsolutePath = Storage::disk('local')->path($newRelativePath);

        unlink($inputPath);
        rename($outputPath, $newAbsolutePath);

        $this->patientFile->update(['upload_status' => 'generating_preview']);

        // 3. THUMBNAIL EXTRACTION (try multiple timestamps to avoid black frames)
        $thumbRelativePath = substr($newRelativePath, 0, -4) . '_thumb.jpg';
        $thumbAbsolutePath = Storage::disk('local')->path($thumbRelativePath);
        $durationSecs = $duration ?: 10;
        $thumbSuccess = false;
        $tryTimestamps = [
            min(1, max(0, $durationSecs - 1)),
            min(2, max(0, $durationSecs - 1)),
            (int) max(0, $durationSecs / 4),
            (int) max(0, $durationSecs / 2),
        ];
        foreach ($tryTimestamps as $ts) {
            $thumbCmd = [
                'ffmpeg', '-y', '-ss', (string) $ts, '-i', $newAbsolutePath,
                '-vframes', '1',
                '-vf', 'scale=-1:300',
                $thumbAbsolutePath
            ];
            $thumbProcess = new Process($thumbCmd);
            $thumbProcess->run();
            if ($thumbProcess->isSuccessful() && file_exists($thumbAbsolutePath) && filesize($thumbAbsolutePath) > 1024) {
                $thumbSuccess = true;
                break;
            }
        }
        $finalThumbPath = $thumbSuccess ? $thumbRelativePath : null;

        // 4. HLS ADAPTIVE STREAMING (varied bitrates for slow networks)
        $hlsDir = substr($newAbsolutePath, 0, -4) . '_hls';
        @mkdir($hlsDir, 0775, true);

        // Choose renditions based on source height
        $renditions = $this->renditionsFor($height ?? 1080);

        // Build master playlist
        $master = "#EXTM3U\n#EXT-X-VERSION:3\n";
        $playlists = [];

        foreach ($renditions as $r) {
            $name = "v_{$r['h']}p.m3u8";
            $segPrefix = "v_{$r['h']}p_";
            $cmd = [
                'ffmpeg', '-y', '-i', $newAbsolutePath,
                '-vf', "scale=-2:{$r['h']}",
                '-c:v', 'libx264',
                '-preset', 'fast',
                '-b:v', (string) $r['bitrate'],
                '-maxrate', (string) $r['maxrate'],
                '-bufsize', (string) $r['bufsize'],
                '-c:a', 'aac', '-b:a', '128k',
                '-hls_time', '6',
                '-hls_list_size', '0',
                '-hls_segment_filename', $hlsDir . '/' . $segPrefix . '%05d.ts',
                '-hls_playlist_type', 'vod',
                $hlsDir . '/' . $name,
            ];
            $p = new Process($cmd);
            $p->setTimeout(3600);
            $p->run();

            if ($p->isSuccessful()) {
                $playlists[] = $name;
                $master .= "#EXT-X-STREAM-INF:PROGRAM-ID=1,BANDWIDTH={$r['bandwidth']},RESOLUTION={$r['w']}x{$r['h']}\n{$name}\n";
            }
        }

        $hlsRelativeDir = null;
        if (count($playlists) >= 1) {
            file_put_contents($hlsDir . '/playlist.m3u8', $master);
            $hlsRelativeDir = dirname($newRelativePath) . '/hls';
            // Move hls folder into /hls subdir expected by FileAccessController
            $expectedDir = dirname($newAbsolutePath) . '/hls';
            if (!is_dir($expectedDir)) @mkdir($expectedDir, 0775, true);

            // remove old hls (if any) and move contents
            foreach (glob($expectedDir . '/*') as $old) @unlink($old);
            foreach (glob($hlsDir . '/*') as $f) {
                copy($f, $expectedDir . '/' . basename($f));
                unlink($f);
            }
            @rmdir($hlsDir);

            // The files are now in dirname(file_path)/hls/*. Use that as marker
            // Store just a flag in hls_path so the accessor can build the URL.
            $hlsRelativeDir = dirname($newRelativePath) . '/hls';
        }

        // 5. COMPLETE
        $dbMeta = $this->patientFile->video_metadata ?? [];
        $dbMeta['codec'] = $codec;
        $dbMeta['optimized'] = true;

        $this->patientFile->update([
            'upload_status' => 'ready',
            'video_metadata' => $dbMeta,
            'file_path' => $newRelativePath,
            'hls_path' => $hlsRelativeDir,
            'thumbnail_path' => $finalThumbPath,
            'duration' => $duration,
            'width' => $width,
            'height' => $height,
        ]);

        Log::channel('upload')->info('video optimized', [
            'file' => $this->patientFile->uuid,
            'hls_playlists' => count($playlists),
            'total_ms' => round((microtime(true) - $t0) * 1000, 2),
        ]);
    }

    /**
     * Choose HLS renditions based on source height.
     */
    private function renditionsFor(int $sourceH): array
    {
        $all = [
            ['h' => 1080, 'w' => 1920, 'bitrate' => '5000k', 'maxrate' => '5350k', 'bufsize' => '7500k', 'bandwidth' => 5350000],
            ['h' => 720,  'w' => 1280, 'bitrate' => '2800k', 'maxrate' => '2996k', 'bufsize' => '4200k', 'bandwidth' => 2996000],
            ['h' => 480,  'w' => 854,  'bitrate' => '1400k', 'maxrate' => '1498k', 'bufsize' => '2100k', 'bandwidth' => 1498000],
            ['h' => 360,  'w' => 640,  'bitrate' => '800k',  'maxrate' => '856k',  'bufsize' => '1200k', 'bandwidth' => 856000],
        ];

        $out = [];
        foreach ($all as $r) {
            if ($r['h'] <= $sourceH) $out[] = $r;
        }

        // Always include at least one low rendition
        if (empty($out)) {
            $out[] = $all[3];
        }

        return $out;
    }

    public function failed(\Throwable $exception): void
    {
        $dbMeta = $this->patientFile->video_metadata ?? [];
        $dbMeta['error'] = $exception->getMessage();
        
        $this->patientFile->update([
            'upload_status' => 'failed',
            'video_metadata' => $dbMeta
        ]);
        
        Log::error("OptimizeVideoJob failed for File {$this->patientFile->uuid}: " . $exception->getMessage());
    }
}
