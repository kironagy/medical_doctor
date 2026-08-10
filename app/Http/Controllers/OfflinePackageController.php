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

        abort(401, 'No local user to own this offline package.');
    }
}
