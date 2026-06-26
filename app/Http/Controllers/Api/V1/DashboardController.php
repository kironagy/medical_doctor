<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientFile;
use App\Models\PatientVisit;
use App\Models\User;

class DashboardController extends Controller
{
    public function __invoke()
    {
        return response()->json([
            'doctors_count' => User::where('role', 'doctor')->count(),
            'patients_count' => Patient::count(),
            'files_count' => PatientFile::count(),
            'recent_patients_count' => Patient::where('created_at', '>=', now()->subDays(7))->count(),
            'total_income' => (float) PatientVisit::sum('cost'),
            'monthly_income' => (float) PatientVisit::whereMonth('visit_date', now()->month)
                ->whereYear('visit_date', now()->year)
                ->sum('cost'),
            'last_generated_at' => now()->toISOString(),
        ]);
    }
}
