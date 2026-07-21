<?php

namespace App\Http\Controllers;

use App\Domains\Users\Models\User;
use App\Services\Mobile\ApiService;
use App\Jobs\FullSyncJob;
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

    // Store credentials for automatic token refresh on 401
    app(\App\Services\Mobile\ApiService::class)->storeCredentials($email, $password);

    // Trigger a lightweight background sync so patients are loaded immediately
    $this->triggerStartupSync();

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

                    // Store credentials for automatic token refresh on 401
                    app(ApiService::class)->storeCredentials($email, $password);

                    // Trigger a lightweight background sync so patients are loaded immediately
                    $this->triggerStartupSync();

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
    /**
     * Trigger a lightweight metadata sync immediately after login.
     * This ensures the local SQLite is populated with the latest patients
     * from the remote API before the DoctorWorkspace page renders.
     *
     * We use dispatch() so the sync runs synchronously in the current request.
     * This adds ~1-3 seconds to login time but guarantees patients are ready
     * when the DoctorWorkspace page loads.
     *
     * For NativePHP mobile, this is called AFTER the UI already has the
     * Inertia props (which may be empty for first launch). The sync ensures
     * that by the time the background syncAndRefresh() runs, the local
     * SQLite is already populated.
     */
    private function triggerStartupSync(): void
    {
        // Only run on NativePHP mobile — the web version uses MySQL directly
        // and doesn't need to sync to a local SQLite cache.
        if (!env('NATIVEPHP_RUNNING', false)) {
            return;
        }

        if (!NetworkStatusService::isOnline()) {
            Log::info('[StartupSync] Skipping — device is offline');
            return;
        }

        try {
            $token = app(ApiService::class)->getToken();
            if (!$token) {
                Log::warning('[StartupSync] Skipping — no API token available');
                return;
            }

            Log::info('[StartupSync] Dispatching background FullSyncJob after login...');
            FullSyncJob::dispatch();
            Log::info('[StartupSync] FullSyncJob dispatched successfully');
        } catch (\Throwable $e) {
            Log::warning('[StartupSync] Failed (non-fatal): ' . $e->getMessage());
        }
    }

    private function mirrorRemoteUser(array $remoteUser, string $password): User
    {
        $uuid = $remoteUser['uuid'] ?? null;

        // If the login response didn't include UUID, fetch it from the /me endpoint
        if (!$uuid && !empty($remoteUser['id'])) {
            try {
                $apiService = app(\App\Services\Mobile\ApiService::class);
                if ($apiService->getToken()) {
                    $meResponse = $apiService->get('/me');
                    $uuid = $meResponse['uuid'] ?? $meResponse['data']['uuid'] ?? null;
                }
            } catch (\Throwable $e) {
                Log::warning('[AuthController] Failed to fetch user UUID from /me endpoint: ' . $e->getMessage());
            }
        }

        // Last resort: generate a local UUID (only used as fallback)
        if (!$uuid) {
            $uuid = (string) \Illuminate\Support\Str::uuid();
            Log::warning('[AuthController] No remote UUID available, generated local UUID: ' . $uuid);
        }

        $attributes = [
            'name'     => $remoteUser['name'] ?? 'Remote User',
            'email'    => $remoteUser['email'],
            'password' => Hash::make($password),
            'role'     => $remoteUser['role'] ?? 'doctor',
            'phone'    => $remoteUser['phone'] ?? null,
            'code'     => $remoteUser['code'] ?? null,
            'uuid'     => $uuid,
        ];

 $localUser = User::where('email', $attributes['email'])->first();

 if ($localUser) {
     $localUser->update($attributes);
 } else {
     $localUser = User::create($attributes);
 }

 $roleName = $attributes['role'] ?? 'doctor';
 if (in_array($roleName, ['super-admin', 'doctor'], true)) {
     $localUser->syncRoles([$roleName]);
 } else {
     $localUser->syncRoles(['doctor']);
 }

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
