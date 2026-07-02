<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class MobileApiLogger
{
    public function handle(Request $request, Closure $next)
    {
        $startTime = microtime(true);
        $requestId = (string) Str::uuid();

        // Log the request
        $this->logRequest($request, $requestId, $startTime);

        try {
            $response = $next($request);
        } catch (\Exception $e) {
            $this->logException($request, $requestId, $e, $startTime);
            throw $e;
        }

        // Log the response
        $this->logResponse($request, $response, $requestId, $startTime);

        return $response;
    }

    protected function logRequest(Request $request, string $requestId, float $startTime): void
    {
        $logger = Log::channel('mobile-api');
        $user = $request->user();

        $logData = [
            'request_id' => $requestId,
            'timestamp' => now()->toIso8601String(),
            'http_method' => $request->method(),
            'full_url' => $request->fullUrl(),
            'route_name' => $request->route()?->getName(),
            'controller' => $request->route()?->getAction()['controller'] ?? null,
            'user' => $user ? [
                'id' => $user->id,
                'uuid' => $user->uuid,
                'email' => $user->email,
            ] : null,
            'doctor_id' => $user?->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_model' => $request->header('X-Device-Model'),
            'platform' => $request->header('X-Platform'),
            'app_version' => $request->header('X-App-Version'),
            'os_version' => $request->header('X-OS-Version'),
            'headers' => $this->maskHeaders($request->headers->all()),
            'content_length' => $request->header('Content-Length'),
            'request_body' => $this->maskSensitiveData($request->all()),
            'uploaded_files' => $this->formatUploadedFiles($request->allFiles()),
            'query_parameters' => $request->query(),
            'execution_start_time' => $startTime,
        ];

        $logger->info("REQUEST START\n" . json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    protected function logResponse(Request $request, Response $response, string $requestId, float $startTime): void
    {
        $logger = Log::channel('mobile-api');
        $endTime = microtime(true);
        $executionTime = round(($endTime - $startTime) * 1000, 2);

        $logData = [
            'request_id' => $requestId,
            'timestamp' => now()->toIso8601String(),
            'http_status' => $response->getStatusCode(),
            'execution_time_ms' => $executionTime,
            'response_size' => strlen($response->getContent()),
        ];

        if ($response instanceof \Illuminate\Http\JsonResponse) {
            $logData['returned_json'] = $response->getData(true);
        }

        if ($response->getStatusCode() >= 400) {
            $logData['errors'] = $this->extractErrors($response);
        }

        $logger->info("RESPONSE END\n" . json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n==================================================\n");
    }

    protected function logException(Request $request, string $requestId, \Exception $e, float $startTime): void
    {
        $logger = Log::channel('mobile-api');
        $endTime = microtime(true);
        $executionTime = round(($endTime - $startTime) * 1000, 2);

        $logData = [
            'request_id' => $requestId,
            'timestamp' => now()->toIso8601String(),
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'stack_trace' => $e->getTraceAsString(),
            'execution_time_ms' => $executionTime,
        ];

        $logger->error("EXCEPTION\n" . json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n==================================================\n");
    }

    protected function maskHeaders(array $headers): array
    {
        $masked = [];
        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            if ($lowerKey === 'authorization') {
                $authValue = is_array($value) ? $value[0] : $value;
                if (str_starts_with($authValue, 'Bearer ')) {
                    $token = substr($authValue, 7);
                    if (strlen($token) > 8) {
                        $maskedToken = substr($token, 0, 4) . str_repeat('*', strlen($token) - 8) . substr($token, -4);
                        $masked[$key] = ['Bearer ' . $maskedToken];
                    } else {
                        $masked[$key] = ['Bearer ' . str_repeat('*', strlen($token))];
                    }
                } else {
                    $masked[$key] = $value;
                }
            } else {
                $masked[$key] = $value;
            }
        }
        return $masked;
    }

    protected function maskSensitiveData(array $data): array
    {
        $sensitiveKeys = ['password', 'password_confirmation', 'token', 'secret'];
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $data[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $data[$key] = $this->maskSensitiveData($value);
            }
        }
        return $data;
    }

    protected function formatUploadedFiles(array $files): array
    {
        $formatted = [];
        foreach ($files as $key => $file) {
            if (is_array($file)) {
                $formatted[$key] = $this->formatUploadedFiles($file);
            } else {
                $formatted[$key] = [
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ];
            }
        }
        return $formatted;
    }

    protected function extractErrors(Response $response): ?array
    {
        if ($response instanceof \Illuminate\Http\JsonResponse) {
            $data = $response->getData(true);
            if (isset($data['errors'])) {
                return $data['errors'];
            }
            if (isset($data['message'])) {
                return [$data['message']];
            }
        }
        return null;
    }
}
