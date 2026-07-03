<?php

namespace App\Repositories\Api\Traits;

use Illuminate\Support\Facades\Log;

trait DebugLogsHttp
{
    private function logHttp(string $method, string $url, array $headers, int $status, float $timeMs): void
    {
        Log::debug(sprintf(
            '[API] %s %s | Status: %d | Time: %.0fms | Token: %s',
            $method,
            $url,
            $status,
            $timeMs,
            isset($headers['Authorization']) ? 'YES' : 'NO'
        ));
    }
}
