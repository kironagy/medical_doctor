<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Domains\Users\Models\User;
use App\Domains\ActivityLogs\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class DoctorController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger
    ) {}

    public function index(Request $request)
    {
        $doctors = User::role('doctor')
            ->select(['id', 'name', 'email', 'phone', 'specialization', 'code', 'status', 'avatar_path'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(fn($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'email' => $d->email,
                'phone' => $d->phone,
                'specialization' => $d->specialization,
                'code' => $d->code,
                'avatar_url' => $d->avatar_url,
            ]);

        return response()->json($doctors);
    }

    public function show(int $doctorId)
    {
        $doctor = User::role('doctor')->findOrFail($doctorId);

        return response()->json([
            'id' => $doctor->id,
            'name' => $doctor->name,
            'email' => $doctor->email,
            'phone' => $doctor->phone,
            'specialization' => $doctor->specialization,
            'code' => $doctor->code,
            'avatar_url' => $doctor->avatar_url,
            'patient_count' => $doctor->patients()->count(),
        ]);
    }

    public function search(Request $request)
    {
        $query = $request->get('q');

        if (!$query || strlen($query) < 2) {
            return response()->json([]);
        }

        $doctors = User::role('doctor')
            ->where('status', 'active')
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('email', 'like', "%{$query}%")
                  ->orWhere('specialization', 'like', "%{$query}%")
                  ->orWhere('code', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get()
            ->map(fn($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'email' => $d->email,
                'specialization' => $d->specialization,
            ]);

        return response()->json($doctors);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
            'phone' => 'nullable|string|max:255',
            'specialization' => 'nullable|string|max:255',
        ]);

        $user->update($validated);

        $this->logger->log('profile_updated', 'User', $user->uuid);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'specialization' => $user->specialization,
                'avatar_url' => $user->avatar_url,
            ],
        ]);
    }

    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|current_password',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['new_password']),
        ]);

        return response()->json(['message' => 'Password updated successfully']);
    }
}
