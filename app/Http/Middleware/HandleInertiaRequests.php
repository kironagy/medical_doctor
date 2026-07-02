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

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? array_merge($request->user()->toArray(), [
                    'roles' => $request->user()->roles->pluck('name'),
                    'role' => $request->user()->roles->first()?->name,
                ]) : null,
            ],
            'is_native_mobile' => $isNativeMobile,
        ];
    }
}
