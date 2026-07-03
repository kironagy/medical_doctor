<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\ApiService;
use App\Services\Mobile\PatientRepository;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ApiService $api,
        private readonly PatientRepository $patients
    ) {}

    public function index()
    {
        $stats = $this->api->get('/dashboard/stats');
        $recentPatients = $this->patients->recent(10);

        return view('mobile.dashboard.index', [
            'stats' => $stats['stats'] ?? [],
            'recentPatients' => $recentPatients['data'] ?? $recentPatients ?? [],
            'user' => $stats['user'] ?? [],
            'pageTitle' => 'Dashboard',
        ]);
    }
}
