<?php

namespace App\Repositories\Api\Traits;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

trait MakesApiRequests
{
    private function baseUrl(): string
    {
        return config('app.mobile_api_url', 'https://prof-hosam-fekry.online/api/v1/mobile');
    }

    private function apiCall(string $method, string $path, array $data = []): Response
    {
        $url = $this->baseUrl() . $path;
        $encryptedToken = session('api_token');

        $token = null;
        if ($encryptedToken) {
            try {
                $token = decrypt($encryptedToken);
            } catch (\Exception $e) {
                session()->forget('api_token');
            }
        }

        $http = Http::timeout(30)
            ->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json'])
            ->when($token, fn($c) => $c->withToken($token));

        $start = microtime(true);

        $response = match (strtoupper($method)) {
            'GET'    => $http->get($url, $data),
            'POST'   => $http->post($url, $data),
            'PUT'    => $http->put($url, $data),
            'DELETE' => $http->delete($url, $data),
            default  => throw new RuntimeException("Unsupported HTTP method: $method"),
        };

        $timeMs = (microtime(true) - $start) * 1000;

        Log::debug(sprintf(
            '[API] %s %s | Status: %d | Time: %.0fms | Token: %s',
            strtoupper($method),
            $url,
            $response->status(),
            $timeMs,
            $token ? 'YES' : 'NO'
        ));

        if ($response->unauthorized()) {
            session()->forget('api_token');
            throw new \Illuminate\Auth\AuthenticationException('Session expired. Please login again.');
        }

        if ($response->failed()) {
            $body = $response->json();
            $message = is_array($body) ? ($body['message'] ?? 'Request failed.') : 'Request failed.';
            throw new RuntimeException($message);
        }

        return $response;
    }
}
