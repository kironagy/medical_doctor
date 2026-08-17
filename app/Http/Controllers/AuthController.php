<?php

namespace App\Http\Controllers;

use App\Services\Mobile\ApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class AuthController extends Controller
{
    /** Durable marker: the EMAIL of the account that signed in on this device. */
    private const DEVICE_SIGNED_IN = 'device_signed_in_email';

    /**
     * The account this device should auto-resume, or null to show the form.
     *
     * Returns the user who ACTUALLY signed in, which is the whole point. This
     * used to be User::first() — the lowest id in the local table — so after
     * signing in as the admin, closing the app and reopening it silently
     * resumed the doctor sitting in row 1 instead. That is why the app kept
     * landing on the doctor page rather than the admin page, and it is a
     * different bug from the login form being unreachable.
     *
     * The marker is written on every successful login and cleared on logout;
     * CACHE_STORE=file on the device, so it survives restarts. The token-file
     * fallback covers installs that were already signed in before this marker
     * existed, so updating the app does not sign everyone out.
     */
    public static function deviceSignedInUser(): ?\App\Domains\Users\Models\User
    {
        $email = Cache::get(self::DEVICE_SIGNED_IN);

        if (is_string($email) && $email !== '') {
            $user = \App\Domains\Users\Models\User::where('email', $email)->first();
            if ($user) {
                return $user;
            }
        }

        // No marker, but a token file — an install that was already signed in
        // before the marker existed. Resume only when the answer is not a
        // guess: one local user is unambiguous, which covers a real
        // single-doctor device and keeps the app update from signing them out.
        // Two or more rows means this device has held several accounts, and
        // picking one is precisely the bug above, so fall through to the login
        // form instead — a single sign-in then records the marker correctly.
        if (ApiService::hasStoredToken()) {
            $users = \App\Domains\Users\Models\User::query()->take(2)->get();

            return $users->count() === 1 ? $users->first() : null;
        }

        return null;
    }

    /**
     * Where a freshly-authenticated user belongs. A super-admin carries the
     * role in the users.role column on the device (mirrored at login) and as a
     * Spatie role on the server, so both are checked.
     */
    public static function homeFor(?\App\Domains\Users\Models\User $user): string
    {
        if ($user && ($user->role === 'super-admin' || $user->hasRole('super-admin'))) {
            return '/admin/doctors';
        }

        return '/workspace';
    }

    public function showLogin(Request $request)
    {
        if (config('database.default') === 'sqlite') {
            // The embedded device is single-user, so normally the very
            // presence of a local row is treated as "stay logged in across
            // app restarts" and auto-authenticates on sight — no need to
            // retype credentials every launch. logout() sets this flag
            // specifically to suspend that for the one visit right after an
            // explicit logout; without it, landing back on /login
            // immediately re-authenticated the same (now token-less) user
            // before the login form ever rendered, which is what made
            // logout look like it "wouldn't let you log in again" — you
            // were never actually seeing the login form.
            //
            // The second condition is what makes it possible to sign in as
            // anyone other than whoever the local database happens to list
            // first. The shipped SQLite database is not empty — it carries a
            // seeded doctor row — so "a row exists" was never evidence that
            // this device had been signed into. On a fresh install the very
            // first request auto-authenticated as that seeded doctor and
            // redirected to /workspace, and the login form was unreachable:
            // the admin (or any other account) simply could not get in, which
            // is exactly what was reported for the app while the website —
            // which never takes this branch — worked fine. deviceSignedInUser()
            // is non-null only once someone has actually completed a login
            // here, and it returns THAT account rather than row 1.
            if (!Cache::pull('device_logged_out')) {
                $user = self::deviceSignedInUser();
                if ($user) {
                    Auth::login($user);
                    return redirect(self::homeFor($user));
                }
            }
        }

        if (Auth::check()) {
            return redirect('/dashboard');
        }

        return Inertia::render('Auth/Login');
    }

    /**
     * Copy the server's Spatie roles onto the local (device) user.
     *
     * The device ships with empty roles/model_has_roles tables, so without
     * this the `role:super-admin` middleware on /admin/* rejects the very
     * admin who just authenticated successfully. findOrCreate() because the
     * role row itself does not exist on the device yet. Also sets the
     * users.role column, which is what the app's own super-admin checks read
     * — an admin's column says 'doctor' on the server, since 'super-admin'
     * lives only as a Spatie role there.
     */
    private function mirrorRemoteRoles(\App\Domains\Users\Models\User $user, array $roles): void
    {
        if (!$roles) {
            return;
        }

        try {
            $guard = config('auth.defaults.guard', 'web');
            foreach ($roles as $roleName) {
                \Spatie\Permission\Models\Role::findOrCreate($roleName, $guard);
            }
            $user->syncRoles($roles);

            if (in_array('super-admin', $roles, true) && $user->role !== 'super-admin') {
                $user->forceFill(['role' => 'super-admin'])->save();
            }
        } catch (\Throwable $e) {
            // Never block a valid login on role mirroring — the app stays
            // usable without it, just without admin routes.
            Log::error('Failed to mirror remote roles locally', [
                'email'   => $user->email,
                'roles'   => $roles,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $attemptSuccess = Auth::attempt($credentials, $request->boolean('remember'));

        if ($attemptSuccess) {
            $request->session()->regenerate();
            // Records WHICH account signed in, so the next cold start resumes
            // this one rather than whichever row happens to be first — see
            // deviceSignedInUser().
            Cache::forever(self::DEVICE_SIGNED_IN, $credentials['email']);
            Cache::forget('device_logged_out');
            // Drop any stale "return to where I was" URL captured by the
            // guest-redirect before this login — a page visited in a
            // previous, now-expired session must never hijack this login's
            // destination (this was silently sending fresh logins back to
            // whatever page had bounced them to /login, sometimes forming
            // a login <-> that-page loop).
            $request->session()->forget('url.intended');

            // Obtain API token for mobile API requests and sync
            try {
                $user = $request->user();
                $token = null;

                if (config('database.default') === 'sqlite') {
                    // On mobile (SQLite), we must obtain a token from the remote production server
                    $tokenResponse = ApiService::loginToRemote($credentials['email'], $credentials['password']);
                    $token = $tokenResponse['token'] ?? null;

                    // Re-mirror roles on every online login, not just the first
                    // one that created the local row. An account whose first
                    // sign-in here predates the server returning `roles` would
                    // otherwise stay stuck without them forever, since this
                    // branch (local password matched) skips the block below.
                    if ($user) {
                        $this->mirrorRemoteRoles(
                            $user,
                            array_values((array) ($tokenResponse['roles'] ?? []))
                        );
                        $user->refresh();
                    }
                } else {
                    // On production (MySQL), generate local Sanctum token directly to avoid HTTP loopback loops
                    $token = $user->createToken('auth_token')->plainTextToken;
                }

                if ($token) {
                    app(ApiService::class)->setToken($token);
                    Log::info('API token acquired successfully');

                    // ── Store encrypted credentials for auto-refresh on 401 ──
                    session(['auth_credentials' => encrypt(json_encode([
                        'email'    => $credentials['email'],
                        'password' => $credentials['password'],
                    ]))]);
                }
            } catch (\Throwable $e) {
                Log::warning('API token acquisition failed: ' . $e->getMessage());
            }

            // Role-based redirect: super-admin goes to admin doctors page, others to dashboard
            $user = $request->user();
            if ($user) {
                $user->refresh();
                if ($user->role === 'super-admin' || $user->hasRole('super-admin')) {
                    return redirect('/admin/doctors');
                }
            }

            return redirect('/dashboard');
        }


        // If local authentication fails, attempt authentication against remote production API
        // (essential for clean installs where the local SQLite database contains 0 users)
        try {
            $tokenResponse = ApiService::loginToRemote($credentials['email'], $credentials['password']);
            if (isset($tokenResponse['token']) && isset($tokenResponse['user'])) {
                $remoteUser = $tokenResponse['user'];

                // Spatie roles from the server. An admin's users.role column
                // still reads 'doctor' — 'super-admin' exists only as a Spatie
                // role — so copying the column alone left the account looking
                // like an ordinary doctor on the device.
                $remoteRoles = array_values((array) ($tokenResponse['roles'] ?? []));
                $isSuperAdmin = in_array('super-admin', $remoteRoles, true);

                // Create or update the user record locally in SQLite
                \App\Domains\Users\Models\User::unguard();
                $localUser = \App\Domains\Users\Models\User::updateOrCreate(
                    ['email' => $remoteUser['email']],
                    [
                        'name' => $remoteUser['name'],
                        'password' => bcrypt($credentials['password']), // Save local hashed password for future offline logins
                        'role' => $isSuperAdmin ? 'super-admin' : ($remoteUser['role'] ?? 'doctor'),
                        'phone' => $remoteUser['phone'] ?? null,
                        'address' => $remoteUser['address'] ?? null,
                        'specialization' => $remoteUser['specialization'] ?? null,
                        'code' => $remoteUser['code'] ?? null,
                        'status' => $remoteUser['status'] ?? 'active',
                        'uuid' => $remoteUser['uuid'] ?? (string) \Illuminate\Support\Str::uuid(),
                    ]
                );
                \App\Domains\Users\Models\User::reguard();

                $this->mirrorRemoteRoles($localUser, $remoteRoles);

                // Authenticate the user session locally
                Auth::login($localUser, $request->boolean('remember'));
                $request->session()->regenerate();
                $request->session()->forget('url.intended');
                Cache::forever(self::DEVICE_SIGNED_IN, $credentials['email']);
                Cache::forget('device_logged_out');

                // Save remote API token and store credentials for the sync engine
                app(ApiService::class)->setToken($tokenResponse['token']);
                session(['auth_credentials' => encrypt(json_encode([
                    'email' => $credentials['email'],
                    'password' => $credentials['password'],
                ]))]);

                Log::info('Successfully authenticated via remote fallback and synced user locally.');

                if ($localUser->role === 'super-admin' || $localUser->hasRole('super-admin')) {
                    return redirect('/admin/doctors');
                }
                return redirect('/dashboard');
            }
        } catch (\Throwable $e) {
            // Log::error (not warning): device LOG_LEVEL=error filters warning
            // out entirely. This is the only place that explains why a device
            // stays on 0 local users despite the doctor reporting a working
            // login — something inside the remote-fallback block (the API
            // login call itself, or the local User::updateOrCreate() upsert)
            // is throwing and getting swallowed here.
            Log::error('Remote fallback authentication failed', [
                'email'     => $credentials['email'] ?? null,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        // ── Clean up the production API token via ApiService singleton ──
        // This clears the session-stored token and the disk file.
        try {
            $apiService = app(\App\Services\Mobile\ApiService::class);
            $apiService->setToken(null);
        } catch (\Throwable $e) {
            Log::warning('Failed to clean up API token on logout: ' . $e->getMessage());
        }

        // Clean up stored credentials
        session()->forget('auth_credentials');
        session()->forget('api_token');
        session()->forget('url.intended');

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (config('database.default') === 'sqlite') {
            // Suspends showLogin()'s/'/'s auto-login for the one visit right
            // after this — see the comment there for why the session alone
            // doesn't cover it.
            Cache::forever('device_logged_out', true);

            // And drop the durable "someone is signed in here" marker, so the
            // login form stays reachable on every later launch too — not just
            // the single visit device_logged_out covers. Without this, logging
            // out and relaunching would auto-resume the same account and there
            // would still be no way to sign in as anyone else.
            Cache::forget(self::DEVICE_SIGNED_IN);

            // Belt-and-suspenders on top of the flag above: kill the whole
            // process so the next launch is a genuine cold boot with no
            // leftover PHP/WebView state at all. No-ops instantly on
            // production (function_exists('nativephp_call') is false there).
            \MedicalPlus\AppControl\Facades\AppControl::exit();
        }

        return redirect('/login');
    }
}
