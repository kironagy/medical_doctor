<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

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
        ]);

        if (Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'success' => true,
                'user'    => Auth::user()
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

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string|min:1',
        ]);

        $credentials = $request->only('email', 'password');
        $remember    = $request->boolean('remember');

        try {
            // Check credentials against the live server API
            $response = \Illuminate\Support\Facades\Http::timeout(10)->post('https://pharmacy.copiacode.com/api/login', [
                'email'    => $credentials['email'],
                'password' => $credentials['password']
            ]);

            if ($response->successful() && $response->json('success')) {
                $userData = $response->json('user');

                // Sync the user locally
                $localUser = User::updateOrCreate(
                    ['email' => $userData['email']],
                    [
                        'name' => $userData['name'] ?? 'User',
                        'role' => $userData['role'] ?? 'user',
                        'password' => Hash::make($credentials['password']), // Cache password locally
                    ]
                );

                // Log the user in locally
                Auth::login($localUser, $remember);
                $request->session()->regenerate();

                return response()->json([
                    'success'  => true,
                    'redirect' => url('/'),
                ]);
            }
        } catch (\Exception $e) {
            // Fallback to local DB if live server is entirely unreachable,
            // or you can choose to fail the login if offline.
            // We will attempt local login as a fallback.
            if (Auth::attempt($credentials, $remember)) {
                $request->session()->regenerate();
                return response()->json([
                    'success'  => true,
                    'redirect' => url('/'),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'تعذر الاتصال بالخادم. ' . $e->getMessage(),
            ], 500);
        }

        // Live server responded but authentication failed
        return response()->json([
            'success' => false,
            'message' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
        ], 401);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }
}
