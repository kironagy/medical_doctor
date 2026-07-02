<?php

namespace App\Http\Middleware;

use App\Domains\Mobile\Services\AutoLoginService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $isNativeMobile = $request->hasHeader('X-NativePHP')
            || $request->header('User-Agent') === 'NativePHP'
            || $request->input('_native_mobile') === '1'
            || app()->environment('nativephp');

        $authUser = null;
        if ($isNativeMobile) {
            // For NativePHP, get user from cache instead of web auth
            $storedUser = \App\Domains\Mobile\Services\MobileSyncService::getStoredUser();
            if ($storedUser) {
                $authUser = array_merge($storedUser, [
                    'roles' => [$storedUser['role'] ?? 'doctor'],
                    'role' => $storedUser['role'] ?? 'doctor',
                ]);
            }
        } else {
            // For web, use regular web auth
            $authUser = $request->user() ? array_merge($request->user()->toArray(), [
                'roles' => $request->user()->roles->pluck('name'),
                'role' => $request->user()->roles->first()?->name,
            ]) : null;
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $authUser,
            ],
            'is_native_mobile' => $isNativeMobile,
        ];
    }
}
