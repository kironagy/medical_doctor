<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use RuntimeException;

class AuthController extends Controller
{
    public function showLogin(Request $request)
    {
        if ($request->user()) {
            return redirect()->intended('dashboard');
        }

        return Inertia::render('Auth/Login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        Log::debug('[AuthController] login() - attempting runtime network-based login');

        // Always try remote API first if online
        if (\App\Services\NetworkStatusService::isOnline()) {
            try {
                return $this->apiLogin($request, $credentials);
            } catch (\RuntimeException $e) {
                Log::warning('[AuthController] API login failed, falling back to local: ' . $e->getMessage());
                \App\Services\NetworkStatusService::setOnline(false);
            } catch (\Exception $e) {
                Log::warning('[AuthController] API login error, falling back to local: ' . $e->getMessage());
                \App\Services\NetworkStatusService::setOnline(false);
            }
        }

        // Fallback to local authentication
        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect()->intended('dashboard');
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    private function apiLogin(Request $request, array $credentials)
    {
        $mobileApiUrl = config('app.mobile_api_url', 'https://prof-hosam-fekry.online/api/v1/mobile');
        $apiUrl = str_replace('/mobile', '', $mobileApiUrl) . '/login';
        $start = microtime(true);

        Log::debug('[AuthController] apiLogin() - Sending POST to ' . $apiUrl);

        $response = Http::timeout(30)->post(
            $apiUrl,
            ['email' => $credentials['email'], 'password' => $credentials['password']]
        );

        $timeMs = (microtime(true) - $start) * 1000;

        Log::debug(sprintf(
            '[AuthController] apiLogin() - Response | Status: %d | Time: %.0fms',
            $response->status(),
            $timeMs
        ));

        if ($response->failed()) {
            $body = $response->json();
            $message = is_array($body)
                ? ($body['message'] ?? $body['errors']['email'][0] ?? 'Invalid credentials.')
                : ($response->serverError() ? 'Server error. Please try again.' : 'Invalid credentials.');

            Log::warning('[AuthController] apiLogin() - FAILED: ' . $message);

            return back()->withErrors(['email' => $message])->onlyInput('email');
        }

        $body = $response->json();
        if (!is_array($body) || !isset($body['token'])) {
            Log::warning('[AuthController] apiLogin() - Invalid response (no token): ' . json_encode($body));
            return back()->withErrors(['email' => 'Invalid response from server.'])->onlyInput('email');
        }

        $token = $body['token'];
        $userData = $body['user'] ?? [];

        Log::debug('[AuthController] apiLogin() - SUCCESS | Token received | User: ' . ($userData['email'] ?? 'unknown'));

        session(['api_token' => encrypt($token)]);
        session(['api_user_data' => $userData]);

        $remoteId = $userData['id'] ?? 1;
        $email = $userData['email'] ?? $credentials['email'];

        // Resolve SQLite unique constraint conflicts by deleting any local stale records that have the same email but a different ID.
        // This happens if the live database was reset but the local SQLite database still has old data.
        \App\Domains\Users\Models\User::where('email', $email)->where('id', '!=', $remoteId)->delete();

        // Persist the user to the local SQLite database for offline constraints
        $user = \App\Domains\Users\Models\User::updateOrCreate(
            ['id' => $remoteId],
            [
                'name' => $userData['name'] ?? $email,
                'email' => $email,
                'role' => $userData['role'] ?? ($userData['roles'][0] ?? 'doctor'),
                'phone' => $userData['phone'] ?? '',
                'address' => $userData['address'] ?? '',
                'specialization' => $userData['specialization'] ?? '',
                'uuid' => $userData['uuid'] ?? (string) \Illuminate\Support\Str::uuid(),
                'avatar_path' => $userData['avatar_path'] ?? null,
                'preferences' => $userData['preferences'] ?? [],
                'status' => $userData['status'] ?? 'active',
                'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(16)), // Required field
            ]
        );

        $roleNames = $userData['roles'] ?? (isset($userData['role']) ? [$userData['role']] : ['doctor']);

        // Persist and sync roles in local SQLite database
        foreach ($roleNames as $name) {
            \Spatie\Permission\Models\Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $user->syncRoles($roleNames);

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended('dashboard');
    }

    public function logout(Request $request)
    {
        try {
            $token = session('api_token');
            if ($token && \App\Services\NetworkStatusService::isOnline()) {
                $mobileApiUrl = config('app.mobile_api_url', 'https://prof-hosam-fekry.online/api/v1/mobile');
                $logoutUrl = str_replace('/mobile', '', $mobileApiUrl) . '/logout';
                Http::withToken(decrypt($token))
                    ->timeout(10)
                    ->post($logoutUrl);
            }
        } catch (\Exception $e) {
            // Ignore logout errors
        }
        session()->forget(['api_token', 'api_user_data']);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
