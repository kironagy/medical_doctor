<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Domains\Patients\Models\Patient;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Mobile\Resources\MobilePatientResource;
use App\Services\NetworkStatusService;
use App\Services\Mobile\ApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ApiService $api
    ) {}

    public function stats(Request $request)
    {
        if (NetworkStatusService::isOnline()) {
            try {
                $response = $this->api->get('/dashboard/stats');
                return response()->json($response);
            } catch (\Throwable $e) {
                Log::warning('[DashboardController] API stats failed, falling back to local: ' . $e->getMessage());
            }
        }

        $user = $request->user();
        $isSuperAdmin = $user->hasRole('super-admin');

        $stats = [
            'total_patients' => Patient::count(),
            'recent_files' => PatientFile::count(),
            'active_shares' => DB::table('patient_shares')->count(),
        ];

        if ($isSuperAdmin) {
            $stats['total_doctors'] = \App\Domains\Users\Models\User::role('doctor')->count();
            $stats['active_doctors'] = \App\Domains\Users\Models\User::role('doctor')
                ->where('status', 'active')
                ->count();
        }

        $recentPatients = Patient::with('primaryDoctor:id,name,email')
            ->latest()
            ->take(10)
            ->get();

        return response()->json([
            'stats' => $stats,
            'recent_patients' => MobilePatientResource::collection($recentPatients),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->getRoleNames()->first(),
                'avatar_url' => $user->avatar_url,
            ],
        ]);
    }
}
