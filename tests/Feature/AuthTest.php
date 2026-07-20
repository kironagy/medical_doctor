<?php

namespace Tests\Feature;

use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function registerDoctor(): User
    {
        return User::create([
            'name'        => 'Dr Smith',
            'email'       => 'doctor@example.com',
            'password'    => Hash::make('password123'),
            'role'        => 'doctor',
            'status'      => 'active',
        ]);
    }

    // ---------------------------------------------------------------
    // Login
    // ---------------------------------------------------------------

    public function test_login_with_valid_credentials_returns_token_and_user(): void
    {
        $this->registerDoctor();

        $response = $this->postJson('/api/v1/login', [
            'email'    => 'doctor@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJson([
                'user' => ['email' => 'doctor@example.com'],
            ]);

        self::assertNotEmpty($response->json('token'));
    }

    public function test_login_with_invalid_credentials_returns_422(): void
    {
        $this->registerDoctor(); // valid password is password123

        $response = $this->postJson('/api/v1/login', [
            'email'    => 'doctor@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    // ---------------------------------------------------------------
    // /me
    // ---------------------------------------------------------------

    public function test_me_endpoint_returns_authenticated_user(): void
    {
        $user  = $this->registerDoctor();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJson([
                'email' => 'doctor@example.com',
                'name'  => 'Dr Smith',
            ]);
    }

    // ---------------------------------------------------------------
    // Logout
    // ---------------------------------------------------------------

    public function test_logout_invalidates_token(): void
    {
        $user  = $this->registerDoctor();
        $tokenResult = $user->createToken('auth_token');

        // Confirm token is valid before logout
        $this->withHeader('Authorization', 'Bearer ' . $tokenResult->plainTextToken)
            ->getJson('/api/v1/me')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $tokenResult->plainTextToken)
            ->postJson('/api/v1/logout')
            ->assertOk()
            ->assertJsonFragment(['message' => 'Logged out successfully']);

        // After logout the personal_access_tokens record for this token must be gone.
        // Checking the DB directly is more reliable than a follow-up HTTP request
        // (transaction-scoped SQLite :memory: can mask immediate deletes).
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id'         => $tokenResult->accessToken->id,
            'tokenable_id' => $user->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Guard check
    // ---------------------------------------------------------------

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
