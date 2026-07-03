<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\ApiService;
use Illuminate\Http\Request;
use RuntimeException;

class AuthController extends Controller
{
    public function __construct(
        private readonly ApiService $api
    ) {}

    public function showLogin()
    {
        if ($this->api->getToken()) {
            return redirect()->route('mobile.dashboard');
        }
        return view('mobile.auth.login');
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        try {
            $result = ApiService::loginToRemote($validated['email'], $validated['password']);
            $this->api->setToken($result['token']);
            return redirect()->route('mobile.dashboard');
        } catch (RuntimeException $e) {
            return back()->withErrors(['email' => $e->getMessage()])->withInput();
        }
    }

    public function logout()
    {
        try {
            $this->api->post('/logout');
        } catch (\Exception $e) {
            // Ignore logout errors
        }

        $this->api->setToken(null);
        return redirect()->route('mobile.login');
    }
}
