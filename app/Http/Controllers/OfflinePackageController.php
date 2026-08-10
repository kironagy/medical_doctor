<?php

namespace App\Http\Controllers;

use App\Domains\Users\Models\User;
use App\Services\Offline\OfflinePackageService;
use Illuminate\Http\Request;

/**
 * Explicit per-patient offline package: download / refresh / list / delete.
 * Always routed to the embedded Laravel instance regardless of connectivity
 * (RequestRouter.kt Rule 2, _native/* is always local) — a package
 * download/refresh is itself the one sanctioned local write, online or off.
 */
class OfflinePackageController extends Controller
{
    public function __construct(
        private readonly OfflinePackageService $service,
    ) {}

    public function download(Request $request, string $patientUuid)
    {
        $package = $this->service->download($patientUuid, $this->resolveOwnerId($request));

        return response()->json($package);
    }

    public function refresh(Request $request, string $patientUuid)
    {
        $package = $this->service->refresh($patientUuid, $this->resolveOwnerId($request));

        return response()->json($package);
    }

    public function index(Request $request)
    {
        $packages = $this->service->list($this->resolveOwnerId($request));

        return response()->json(['data' => $packages]);
    }

    public function destroy(Request $request, string $patientUuid)
    {
        $this->service->delete($patientUuid, $this->resolveOwnerId($request));

        return response()->json(['message' => 'Offline package removed']);
    }

    /**
     * Same "current local user" resolution pattern used throughout the
     * embedded app's controllers (e.g. Mobile\NoteController::
     * resolveCurrentUserId()) — the single-user-device auto-login model.
     *
     * Falls back to fetching the logged-in doctor's profile from production
     * (via /api/v1/me, same call BootstrapController::refreshCache() makes)
     * and upserting a local row on the spot, rather than just failing.
     * refreshCache() is only ever fired as fire-and-forget right after
     * login (see Login.vue) — a user who logs in and immediately taps
     * "Download Offline" can reach this endpoint before that background
     * call has finished populating the local users table, which otherwise
     * has nothing else to write it. Confirmed on-device via the app's own
     * laravel.log: "No local user to own this offline package." right
     * after a fresh login + immediate download attempt.
     */
    private function resolveOwnerId(Request $request): int
    {
        $user = $request->user();
        if ($user) {
            return $user->id;
        }

        $localUser = User::first();
        if ($localUser) {
            return $localUser->id;
        }

        $created = $this->fetchAndCacheCurrentUser($request);
        if ($created) {
            return $created->id;
        }

        abort(401, 'No local user to own this offline package. Please try again once you are online.');
    }

    private function fetchAndCacheCurrentUser(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (!$token) {
            try {
                $token = app(\App\Services\Mobile\ApiService::class)->getToken();
            } catch (\Throwable $e) {
                $token = null;
            }
        }
        if (!$token) {
            return null;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->withToken($token)
                ->withHeaders(['Accept' => 'application/json'])
                ->get(rtrim(config('app.mobile_api_url', config('app.url')), '/') . '/api/v1/me');

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();
            if (empty($data['id']) || empty($data['email'])) {
                return null;
            }

            User::unguard();
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'id'             => $data['id'],
                    'name'           => $data['name'] ?? 'Doctor',
                    'role'           => $data['role'] ?? 'doctor',
                    'password'       => bcrypt(\Illuminate\Support\Str::random(32)),
                    'uuid'           => $data['uuid'] ?? (string) \Illuminate\Support\Str::uuid(),
                    'specialization' => $data['specialization'] ?? null,
                ]
            );
            User::reguard();

            return $user;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[OfflinePackageController] fetchAndCacheCurrentUser failed: ' . $e->getMessage());
            return null;
        }
    }
}
