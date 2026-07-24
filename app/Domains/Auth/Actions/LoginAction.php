<?php

namespace App\Domains\Auth\Actions;

use App\Domains\Users\Models\User;
use App\Domains\ActivityLogs\Services\ActivityLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LoginAction
{
    public function __construct(
        private readonly ActivityLogger $logger
    ) {}

    public function execute(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $tokenId = explode('|', $token, 2)[0] ?? 'unknown';
        $existingCount = $user->tokens()->count();

        Log::info('[DIAG.LoginAction] Token created', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'token_sanctum_id' => $tokenId,
            'token_prefix' => substr($token, 0, 20) . '...' . substr($token, -4),
            'token_length' => strlen($token),
            'existing_tokens_count' => $existingCount,
        ]);

        return [
            'user' => $user,
            'token' => $token,
        ];
    }
}
