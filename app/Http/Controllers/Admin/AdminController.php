<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Domains\Users\Models\User;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function index()
    {
        $totalDoctors = User::role('doctor')->count();
        $activeDoctors = User::role('doctor')->where('status', 'active')->count();
        $recentDoctors = User::role('doctor')->latest()->take(5)->get(['id', 'name', 'email', 'specialization', 'status', 'created_at']);

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'total_doctors' => $totalDoctors,
                'active_doctors' => $activeDoctors,
                'inactive_doctors' => $totalDoctors - $activeDoctors,
            ],
            'recentDoctors' => $recentDoctors,
        ]);
    }
}
