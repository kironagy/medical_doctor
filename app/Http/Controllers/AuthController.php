<?php

namespace App\Http\Controllers;

use App\Domains\Users\Models\User;
use App\Services\Mobile\ApiService;
use App\Services\NetworkStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
     * Hybrid login:
     *  1. Try local SQLite first (works offline, fast).
     *  2. If local fails but we are online, try the remote API — if it succeeds,
     *     mirror the remote user into local SQLite so future logins work offline.
     *  3. If both fail, return the standard "invalid credentials" error.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $email = $credentials['email'];
        $password = $credentials['password'];

        // Step 1: try local SQLite auth
        if (Auth::attempt(['email' => $email, 'password' => $password], $request->boolean('remember'))) {
            $request->session()->regenerate();

            // Best-effort remote token (non-blocking for offline)
            $this->acquireRemoteToken($email, $password);

            if ($request->wantsJson() && $request->header('X-Inertia') !== 'true') {
                return response()->json(['redirect' => $this->getRoleBasedUrl($request)]);
            }

            return $this->roleBasedRedirect($request);
        }

        // Step 2: try remote API if local auth failed and we are online
        if (NetworkStatusService::isOnline()) {
            try {
                $remoteResponse = ApiService::loginToRemote($email, $password);

                if (isset($remoteResponse['token'], $remoteResponse['user'])) {
                    $remoteUser = $remoteResponse['user'];

                    // Mirror/refresh the remote user into local SQLite so future
                    // offline logins work against the local database.
                    $localUser = $this->mirrorRemoteUser($remoteUser, $password);

                    Auth::login($localUser, $request->boolean('remember'));
                    $request->session()->regenerate();

                    app(ApiService::class)->setToken($remoteResponse['token']);
                    Log::info('Hybrid login: authenticated via remote API and mirrored user locally.', [
                        'user_id' => $localUser->id,
                        'email'   => $localUser->email,
                    ]);

                    if ($request->wantsJson() && $request->header('X-Inertia') !== 'true') {
                        return response()->json(['redirect' => $this->getRoleBasedUrl($request)]);
                    }

                    return $this->roleBasedRedirect($request);
                }
            } catch (\Throwable $e) {
                Log::warning('Hybrid login: remote API login attempt failed.', [
                    'email'  => $email,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        // Step 3: both paths failed
        if ($request->wantsJson() && $request->header('X-Inertia') !== 'true') {
            return response()->json([
                'errors' => ['email' => ['The provided credentials do not match our records.']]
            ], 422);
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    /**
     * Best-effort remote token acquisition — must never block the login flow
     * when the device is offline.
     */
    private function acquireRemoteToken(string $email, string $password): void
    {
        if (!NetworkStatusService::isOnline()) {
            return;
        }

        try {
            $tokenResponse = ApiService::loginToRemote($email, $password);
            if (isset($tokenResponse['token'])) {
                app(ApiService::class)->setToken($tokenResponse['token']);
                Log::info('Remote API token acquired successfully');
            }
        } catch (\Throwable $e) {
            Log::warning('Remote API login failed, sidebar will use local data: ' . $e->getMessage());
        }
    }

    /**
     * Create or update the local user record from the remote API response.
     * Keeps the local password hash in sync with the supplied password so
     * subsequent offline Auth::attempt() calls succeed.
     */
    private function mirrorRemoteUser(array $remoteUser, string $password): User
    {
        $attributes = [
            'name'     => $remoteUser['name'] ?? 'Remote User',
            'email'    => $remoteUser['email'],
            'password' => Hash::make($password),
            'role'     => $remoteUser['role'] ?? 'doctor',
            'phone'    => $remoteUser['phone'] ?? null,
            'code'     => $remoteUser['code'] ?? null,
        ];

        if (!empty($remoteUser['uuid'])) {
            $attributes['uuid'] = $remoteUser['uuid'];
        }

 $localUser = User::where('email', $attributes['email'])->first();

 if ($localUser) {
     $localUser->update($attributes);
 } else {
     // Create with the remote ID to keep primary_doctor_id matching across sync
     $localUser = User::create($attributes);
 }

 // Assign role via Spatie — always, whether user was just created or already existed.
 // Without this, hasRole('doctor') returns false on subsequent logins and the
 // doctor lands on the admin dashboard instead of the workspace.
 $roleName = $attributes['role'] ?? 'doctor';
 if (in_array($roleName, ['super-admin', 'doctor'], true)) {
     $localUser->syncRoles([$roleName]);
 } else {
     $localUser->syncRoles(['doctor']);
 }

 // Force the local user ID to match the remote ID so DoctorIsolationScope
 // (which filters patients by auth()->id()) finds the correct records.
 // Patients synced from the remote API store primary_doctor_id as the
 // remote user ID, so local and remote IDs must be identical.
 if (!empty($remoteUser['id'])) {
     $localUser->id = $remoteUser['id'];
     $localUser->saveQuietly();
 }

        return $localUser->fresh();
    }

    /**
     * Redirect to the role-appropriate landing page after successful login.
     */
    private function getRoleBasedUrl(Request $request): string
    {
        $user = $request->user();
        if ($user && ($user->role === 'super-admin' || $user->hasRole('super-admin'))) {
            return '/admin/doctors';
        }
    return '/workspace';
}

/**
 * Redirect to the role-appropriate landing page after successful login.
     */
    private function roleBasedRedirect(Request $request)
    {
        return redirect()->intended($this->getRoleBasedUrl($request));
    }

    public function logout(Request $request)
    {
        // Clear the persisted API token from local DB (so startup sync doesn't reuse it)
        try {
            app(ApiService::class)->setToken(null);
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
