<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\ApiService;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(
        private readonly ApiService $api
    ) {}

    public function index()
    {
        $stats = $this->api->get('/dashboard/stats');
        return view('mobile.profile.index', [
            'user' => $stats['user'] ?? [],
            'pageTitle' => 'Profile',
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255',
            'phone' => 'nullable|string|max:255',
            'specialization' => 'nullable|string|max:255',
        ]);

        try {
            $this->api->put('/profile', $validated);
            return redirect()->route('mobile.profile')
                ->with('success', 'Profile updated.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function password(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        try {
            $this->api->put('/profile/password', [
                'current_password' => $validated['current_password'],
                'new_password' => $validated['new_password'],
                'new_password_confirmation' => $validated['new_password_confirmation'],
            ]);
            return redirect()->route('mobile.profile')
                ->with('success', 'Password updated.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }
}
