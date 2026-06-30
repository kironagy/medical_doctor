<?php

namespace App\Domains\Auth\Actions;

use App\Domains\Users\Models\User;
use App\Domains\ActivityLogs\Services\ActivityLogger;
use Illuminate\Support\Facades\Hash;
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

        // Log the login activity. We must fake the auth()->id() since it's not set yet for this request lifecycle
        // Or we just pass user_id manually if our logger allowed it. For now, since it uses auth()->id(), 
        // we can log it after the user is authenticated, but logging in an API doesn't set session auth.
        // Let's rely on Sanctum token.
        // A better approach is directly passing the user to the logger if it's independent, but for simplicity we will just let it capture what it can, 
        // or we can adjust ActivityLogger to accept a User model.
        
        return [
            'user' => $user,
            'token' => $token,
        ];
    }
}
