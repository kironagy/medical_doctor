<?php

namespace App\Http\Controllers;

use App\Services\MobileAuthenticationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /**
     * API Endpoint (Live Server): Verifies credentials and returns user JSON.
     */
    public function apiLogin(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:120',
        ]);

        if (Auth::attempt($request->only('email', 'password'))) {
            $user = Auth::user();

            return response()->json([
                'success' => true,
                'token_type' => 'Bearer',
                'access_token' => $user->createToken($request->input('device_name', 'mobile'))->plainTextToken,
                'user' => $user,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
        ], 401);
    }
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect('/');
        }
        return view('login');
    }

    public function login(Request $request, MobileAuthenticationService $auth)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string|min:1',
        ]);

        $result = $auth->login(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->boolean('remember'),
        );

        if ($result['success']) {
            $request->session()->regenerate();

            // Generate a local token for the frontend to use
            $user = Auth::user();
            $localToken = $user->createToken('web_session')->plainTextToken;

            return response()->json([
                'success' => true,
                'redirect' => url('/'),
                'mode' => $result['mode'],
                'token_type' => 'Bearer',
                'access_token' => $localToken,
                'user' => $result['user'],
            ]);
        }

        return response()->json([
            'success' => false,
            'mode' => $result['mode'] ?? 'online',
            'message' => $result['message'] ?? 'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
        ], $result['status'] ?? 401);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }
}
