<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domains\Auth\Actions\LoginAction;
use App\Domains\ActivityLogs\Services\ActivityLogger;
use Illuminate\Http\Request;

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

        return response()->json([
            'user' => $result['user'],
            'token' => $result['token'],
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
