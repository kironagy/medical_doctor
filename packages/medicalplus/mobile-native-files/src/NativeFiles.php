<?php

namespace MedicalPlus\NativeFiles;

/**
 * PHP side of the NativeFiles bridge, same shape as
 * \MedicalPlus\BackgroundSync\BackgroundSync: nativephp_call() only exists
 * inside the native runtime, so every method here is a silent no-op on the
 * production server.
 */
class NativeFiles
{
    /**
     * Hand $url to Android's DownloadManager. It downloads in the background
     * with the system progress notification and saves into the public
     * Downloads folder.
     *
     * $cookie is forwarded as a Cookie header because a device-local URL is
     * served by the embedded Laravel, which would otherwise answer the
     * DownloadManager's (session-less) request with the login page.
     */
    public function save(string $url, string $fileName, ?string $mime = null, ?string $cookie = null): array
    {
        return $this->call('NativeFiles.Save', array_filter([
            'url'       => $url,
            'file_name' => $fileName,
            'mime'      => $mime,
            'cookie'    => $cookie,
        ], fn ($v) => $v !== null));
    }

    /**
     * Write bytes the caller already holds (base64) into Downloads. Used for
     * files that live only on this device, which DownloadManager cannot fetch
     * — it runs in a separate system process and can't reach the app's
     * embedded server.
     */
    public function saveBytes(string $base64, string $fileName, ?string $mime = null): array
    {
        return $this->call('NativeFiles.SaveBytes', array_filter([
            'data'      => $base64,
            'file_name' => $fileName,
            'mime'      => $mime,
        ], fn ($v) => $v !== null));
    }


    /**
     * Expose a device-local file on the loopback media server and return a URL
     * <video> can stream. Falls back to ['success' => false] off-device.
     */
    public function serve(string $absolutePath, ?string $token = null): array
    {
        return $this->call('NativeFiles.Serve', array_filter([
            'path'  => $absolutePath,
            'token' => $token,
        ], fn ($v) => $v !== null));
    }

    private function call(string $method, array $payload): array
    {
        if (!function_exists('nativephp_call')) {
            return ['success' => false, 'error' => 'not running on device'];
        }

        try {
            $result = nativephp_call($method, json_encode($payload));

            return is_array($result) ? $result : ['success' => true];
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
