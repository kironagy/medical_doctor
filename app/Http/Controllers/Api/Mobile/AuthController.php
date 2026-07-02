<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:255',
        ]);

        $user = \App\Domains\Users\Models\User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken($request->device_name ?? 'mobile-app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'specialization' => $user->specialization,
                'phone' => $user->phone,
                'avatar_url' => $user->avatar_url,
                'preferences' => $user->preferences ?? [],
            ],
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'uuid' => $user->uuid,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'specialization' => $user->specialization,
            'phone' => $user->phone,
            'avatar_url' => $user->avatar_url,
            'preferences' => $user->preferences ?? [],
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->token);

        if (!$token || !$token->can('mobile-access')) {
            return response()->json(['message' => 'Invalid or expired token.'], 401);
        }

        $user = $token->tokenable;

        $token->delete();

        $newToken = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'token' => $newToken,
            'expires_in' => null,
        ]);
    }

    public function registerDevice(Request $request): JsonResponse
    {
        $request->validate([
            'device_id' => 'required|string|max:255',
            'platform' => 'nullable|string|in:ios,android',
            'push_token' => 'nullable|string',
        ]);

        $user = $request->user();

        $device = \App\Domains\Mobile\Models\MobileDevice::updateOrCreate(
            [
                'user_id' => $user->id,
                'device_id' => $request->device_id,
            ],
            [
                'platform' => $request->platform,
                'push_token' => $request->push_token,
                'last_sync_at' => now(),
            ]
        );

        return response()->json([
            'device_uuid' => $device->uuid,
            'last_sync_at' => $device->last_sync_at,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }
}
