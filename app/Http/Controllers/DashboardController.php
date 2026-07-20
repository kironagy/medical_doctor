<?php

namespace App\Http\Controllers;

use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(
        private readonly PatientRepositoryInterface $patientRepo,
        private readonly UserRepositoryInterface $userRepo,
    ) {}

    public function index()
    {
        $user = auth()->user();

    if ($user->hasRole('doctor')) {
        return redirect('/workspace');
    }

        $isSuperAdmin = $user->hasRole('super-admin');
        $stats = $this->patientRepo->stats();

        if ($isSuperAdmin) {
            $doctors = $this->userRepo->doctors();
            $stats['total_doctors'] = count($doctors);
            $stats['active_doctors'] = count(array_filter($doctors, fn($d) => ($d['status'] ?? '') === 'active'));
        }

        return Inertia::render('Dashboard/Index', [
            'stats' => $stats,
            'isSuperAdmin' => $isSuperAdmin,
        ]);
    }
}
