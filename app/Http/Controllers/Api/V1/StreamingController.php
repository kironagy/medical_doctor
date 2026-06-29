<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StreamingController extends Controller
{
    public function stream(Request $request)
    {
        $pathStr = $request->query('path');
        if (str_starts_with($pathStr, '/storage/')) {
            $pathStr = substr($pathStr, 9); // Remove /storage/
        }

        $fullPath = storage_path('app/public/' . $pathStr);

        if (!file_exists($fullPath)) {
            abort(404);
        }

        // BinaryFileResponse handles partial ranges automatically
        $response = new BinaryFileResponse($fullPath);
        $response->setAutoEtag();
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Headers', 'Range, Keep-Alive, Content-Type');
        $response->headers->set('Access-Control-Expose-Headers', 'Content-Range, Content-Length, Accept-Ranges');

        return $response;
    }
}
