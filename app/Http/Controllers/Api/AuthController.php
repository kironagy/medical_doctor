<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domains\Auth\Actions\LoginAction;
use App\Domains\ActivityLogs\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger
    ) {}

    public function login(Request $request, LoginAction $loginAction)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $result = $loginAction->execute($request->email, $request->password);

        $this->logger->log('login', 'User', $result['user']->uuid, [], $result['user']);

        $tokenId = explode('|', $result['token'], 2)[0] ?? 'unknown';

        Log::info('[DIAG.AuthController] Login success', [
            'user_id' => $result['user']->id,
            'user_email' => $result['user']->email,
            'token_sanctum_id' => $tokenId,
            'token_prefix' => substr($result['token'], 0, 20) . '...' . substr($result['token'], -4),
            'token_length' => strlen($result['token']),
        ]);

        return response()->json([
            'user' => $result['user'],
            'token' => $result['token'],
            // Spatie role names, which are NOT part of the serialized user and
            // are NOT the same thing as the users.role column — admin accounts
            // carry 'super-admin' here while their column still says 'doctor'.
            // The embedded app has its own (empty) roles tables, so this is the
            // only way it can learn that the account signing in is an admin.
            'roles' => $result['user']->getRoleNames(),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        
        if ($user) {
            $user->currentAccessToken()->delete();
            $this->logger->log('logout', 'User', $user->uuid, [], $user);
        }

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}
