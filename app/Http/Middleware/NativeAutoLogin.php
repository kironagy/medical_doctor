<?php

namespace App\Http\Middleware;

use App\Domains\Mobile\Services\AutoLoginService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NativeAutoLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        $isNative = $request->hasHeader('X-NativePHP')
            || $request->header('User-Agent') === 'NativePHP'
            || app()->environment('nativephp');

        if ($isNative && AutoLoginService::shouldAutoLogin()) {
            $user = AutoLoginService::autoLogin();

            if ($user && $request->routeIs('login')) {
                return redirect('/workspace');
            }
        }

        return $next($request);
    }
}
