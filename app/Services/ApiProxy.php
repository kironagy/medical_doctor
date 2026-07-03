<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ApiProxy
{
    public static function isEnabled(): bool
    {
        return env('NATIVEPHP_APP_ID') !== null;
    }

    private static function client(): PendingRequest
    {
        $encryptedToken = session('api_token');
        $token = $encryptedToken ? decrypt($encryptedToken) : null;
        return Http::timeout(30)
            ->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json'])
            ->when($token, fn($c) => $c->withToken($token));
    }

    private static function baseUrl(): string
    {
        return config('app.mobile_api_url', 'https://prof-hosam-fekry.online/api/v1/mobile');
    }

    public static function get(string $path, array $query = []): Response
    {
        return self::client()->get(self::baseUrl() . $path, $query);
    }

    public static function post(string $path, array $data = []): Response
    {
        return self::client()->post(self::baseUrl() . $path, $data);
    }

    public static function put(string $path, array $data = []): Response
    {
        return self::client()->put(self::baseUrl() . $path, $data);
    }

    public static function delete(string $path): Response
    {
        return self::client()->delete(self::baseUrl() . $path);
    }

    public static function proxyResponse(Response $response, int $defaultStatus = 200): \Illuminate\Http\JsonResponse
    {
        return response()->json($response->json() ?: [], $response->status() ?: $defaultStatus);
    }
}
