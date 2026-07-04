<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NativePHPProfilerMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $startTime = microtime(true);
        $memBefore = memory_get_usage();

        Log::channel('single')->info('REQUEST_START', [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'memory_before' => $memBefore
        ]);

        $queryCount = 0;
        $queryTime = 0;

        DB::listen(function ($query) use (&$queryCount, &$queryTime) {
            $queryCount++;
            $queryTime += $query->time;
        });

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            Log::channel('single')->error('REQUEST_EXCEPTION', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        $endTime = microtime(true);
        $memAfter = memory_get_usage();
        $peakMem = memory_get_peak_usage();
        $executionTime = ($endTime - $startTime) * 1000;

        $route = $request->route();
        $routeName = $route ? $route->getName() : 'unknown';
        $controller = $route ? $route->getActionName() : 'unknown';
        
        $responseSize = 0;
        if (method_exists($response, 'getContent')) {
            $responseSize = strlen($response->getContent() ?: '');
        }

        Log::channel('single')->info('REQUEST_FINISHED', [
            'url' => $request->fullUrl(),
            'route' => $routeName,
            'controller' => $controller,
            'execution_time_ms' => round($executionTime, 2),
            'memory_before' => round($memBefore / 1024 / 1024, 2) . ' MB',
            'memory_after' => round($memAfter / 1024 / 1024, 2) . ' MB',
            'peak_memory' => round($peakMem / 1024 / 1024, 2) . ' MB',
            'sql_query_count' => $queryCount,
            'sql_time_ms' => round($queryTime, 2),
            'response_size_bytes' => $responseSize,
            'response_size_mb' => round($responseSize / 1024 / 1024, 2) . ' MB'
        ]);

        return $response;
    }
}
