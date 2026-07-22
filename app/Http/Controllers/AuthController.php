<?php

namespace App\Http\Controllers;

use App\Services\Mobile\ApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function showLogin(Request $request)
    {
        if (Auth::check()) {
            return redirect('/');
        }
        return Inertia::render('Auth/Login');
    }

    /**
     * API-first login: authenticate against the remote API,
     * then establish a local session.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $email = $credentials['email'];
        $password = $credentials['password'];

        try {
            $remoteResponse = ApiService::loginToRemote($email, $password);

            if (isset($remoteResponse['token'], $remoteResponse['user'])) {
                $remoteUser = $remoteResponse['user'];

                // Create or update local user record from remote API response
                $localUser = \App\Domains\Users\Models\User::updateOrCreate(
                    ['email' => $remoteUser['email']],
                    [
                        'name'     => $remoteUser['name'] ?? 'User',
                        'password' => \Illuminate\Support\Facades\Hash::make($password),
                        'role'     => $remoteUser['role'] ?? 'doctor',
                        'phone'    => $remoteUser['phone'] ?? null,
                        'code'     => $remoteUser['code'] ?? null,
                        'uuid'     => $remoteUser['uuid'] ?? (string) \Illuminate\Support\Str::uuid(),
                    ]
                );

                $roleName = $remoteUser['role'] ?? 'doctor';
                if (in_array($roleName, ['super-admin', 'doctor'], true)) {
                    $localUser->syncRoles([$roleName]);

        session(['api_token_raw' => $response['token']]);
                }

                Auth::login($localUser, $request->boolean('remember'));
                $request->session()->regenerate();

                app(ApiService::class)->setToken($remoteResponse['token']);
                app(ApiService::class)->storeCredentials($email, $password);

                Log::info('API login successful', [
                    'user_id' => $localUser->id,
                    'email'   => $localUser->email,
                ]);

                if ($request->wantsJson() && $request->header('X-Inertia') !== 'true') {
                    return response()->json(['redirect' => $this->getRoleBasedUrl($request)]);
                }

                return $this->roleBasedRedirect($request);
            }

            throw new \RuntimeException('Invalid response from server.');
        } catch (\Throwable $e) {
            Log::warning('API login failed', [
                'email'  => $email,
                'reason' => $e->getMessage(),
            ]);

            if ($request->wantsJson() && $request->header('X-Inertia') !== 'true') {
                return response()->json([
                    'errors' => ['email' => [$e->getMessage() ?: 'The provided credentials do not match our records.']]
                ], 422);
            }

            return back()->withErrors([
                'email' => $e->getMessage() ?: 'The provided credentials do not match our records.',
            ])->onlyInput('email');
        }
    }

    private function getRoleBasedUrl(Request $request): string
    {
        $user = $request->user();
        if ($user && ($user->role === 'super-admin' || $user->hasRole('super-admin'))) {
            return '/admin/doctors';
        }
        return '/workspace';
    }

    private function roleBasedRedirect(Request $request)
    {
        return redirect()->intended($this->getRoleBasedUrl($request));
    }

    public function logout(Request $request)
    {
        try {
            app(ApiService::class)->setToken(null);
            app(ApiService::class)->clearCredentials();
        } catch (\Throwable $e) {}

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->wantsJson() && $request->header('X-Inertia') !== 'true') {
            return response()->json(['success' => true]);
        }

        return redirect('/login');
    }
}
