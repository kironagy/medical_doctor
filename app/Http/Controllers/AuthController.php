<?php

namespace App\Http\Controllers;

use App\Domains\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use RuntimeException;

class AuthController extends Controller
{
    public function showLogin()
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $isMobile = env('NATIVEPHP_APP_ID') !== null;

        if ($isMobile) {
            return $this->apiLogin($request, $credentials);
        }

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
        $response = Http::timeout(30)->post(
            'https://prof-hosam-fekry.online/api/v1/login',
            ['email' => $credentials['email'], 'password' => $credentials['password']]
        );

        if ($response->failed()) {
            $body = $response->json();
            $message = is_array($body)
                ? ($body['message'] ?? $body['errors']['email'][0] ?? 'Invalid credentials.')
                : 'Invalid credentials.';
            return back()->withErrors(['email' => $message])->onlyInput('email');
        }

        $body = $response->json();
        if (!is_array($body) || !isset($body['token'])) {
            return back()->withErrors(['email' => 'Invalid response from server.'])->onlyInput('email');
        }

        $token = $body['token'];
        $userData = $body['user'] ?? [];

        $user = User::updateOrCreate(
            ['email' => $userData['email'] ?? $credentials['email']],
            [
                'name' => $userData['name'] ?? $credentials['email'],
                'password' => bcrypt($credentials['password']),
            ]
        );

        session(['api_token' => encrypt($token)]);
        session(['api_user' => encrypt($userData)]);

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended('dashboard');
    }

    public function logout(Request $request)
    {
        if (env('NATIVEPHP_APP_ID') !== null) {
            try {
                $token = session('api_token');
                if ($token) {
                    Http::withToken(decrypt($token))
                        ->timeout(10)
                        ->post('https://prof-hosam-fekry.online/api/v1/logout');
                }
            } catch (\Exception $e) {
                // Ignore logout errors
            }
            session()->forget(['api_token', 'api_user']);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
