<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MobileAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = session('api_token');

        if (!$token) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return redirect()->route('mobile.login');
        }

        return $next($request);
    }
}
