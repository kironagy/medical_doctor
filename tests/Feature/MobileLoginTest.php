<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MobileLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_posts_to_configured_remote_api_and_caches_user_locally(): void
    {
        config(['mobile.api_url' => 'http://prof-hosam-fekry.online/api']);

        Http::fake([
            'prof-hosam-fekry.online/api/v1/auth/login' => Http::response([
                'token_type' => 'Bearer',
                'access_token' => 'server-token',
                'user' => [
                    'uuid' => '11111111-1111-4111-8111-111111111111',
                    'name' => 'Doctor',
                    'email' => 'doctor@example.com',
                    'role' => 'doctor',
                    'phone' => '123',
                    'specialization' => 'General',
                ],
            ]),
            'prof-hosam-fekry.online/api/v1/sync/seed' => Http::response([
                'server_time' => now()->toISOString(),
                'tables' => [
                    'patients' => [],
                    'patient_files' => [],
                    'patient_visits' => [],
                    'file_categories' => [],
                    'users' => [],
                ],
            ]),
            'prof-hosam-fekry.online/api/v1/sync/changes*' => Http::response([
                'server_time' => now()->toISOString(),
                'tables' => [
                    'patients' => [],
                    'patient_files' => [],
                    'patient_visits' => [],
                    'file_categories' => [],
                    'users' => [],
                ],
            ]),
        ]);

        $response = $this->postJson('/login', [
            'email' => 'doctor@example.com',
            'password' => 'secret',
            'remember' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('mode', 'online');

        $this->assertNotEmpty($response->json('access_token'));

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'http://prof-hosam-fekry.online/api/v1/auth/login'
            && $request['email'] === 'doctor@example.com'
            && $request['password'] === 'secret');

        $this->assertAuthenticated();
        $this->assertTrue(Hash::check('secret', User::where('email', 'doctor@example.com')->first()->password));
    }

    public function test_login_falls_back_to_local_password_hash_when_remote_api_is_unreachable(): void
    {
        User::create([
            'name' => 'Cached Doctor',
            'email' => 'cached@example.com',
            'password' => Hash::make('offline-secret'),
            'role' => 'doctor',
        ]);

        Http::fake(function () {
            throw new ConnectionException('Network unavailable');
        });

        $response = $this->postJson('/login', [
            'email' => 'cached@example.com',
            'password' => 'offline-secret',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('mode', 'offline');

        $this->assertAuthenticatedAs(User::where('email', 'cached@example.com')->first());
    }
}
