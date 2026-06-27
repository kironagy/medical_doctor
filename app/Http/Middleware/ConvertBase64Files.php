<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class ConvertBase64Files
{
    /**
     * Intercept and convert JSON base64 files into UploadedFile instances.
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->isJson()) {
            $all = $request->all();

            if (isset($all['file']) && is_array($all['file']) && !empty($all['file']['is_file'])) {
                $fileData = $all['file'];
                $base64 = $fileData['data'] ?? '';
                $name = $fileData['name'] ?? 'upload.bin';
                $mimeType = $fileData['type'] ?? 'application/octet-stream';

                if (!empty($base64)) {
                    $decoded = base64_decode($base64);

                    // Save raw binary to a temporary file
                    $tmpPath = tempnam(sys_get_temp_dir(), 'upl');
                    file_put_contents($tmpPath, $decoded);

                    // Create standard Laravel UploadedFile instance
                    $uploadedFile = new UploadedFile(
                        $tmpPath,
                        $name,
                        $mimeType,
                        null,
                        true // test mode (skips is_uploaded_file check)
                    );

                    // Map to request files collection
                    $request->files->set('file', $uploadedFile);

                    // Update request merged input array
                    $all['file'] = $uploadedFile;
                    $request->merge($all);
                }
            }
        }

        return $next($request);
    }
}
