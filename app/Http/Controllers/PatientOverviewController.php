<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\PatientFile;
use App\Models\PatientVisit;
use Illuminate\Http\Request;

class PatientOverviewController extends Controller
{
    /**
     * GET /api/patients/{id}/overview
     * Returns lightweight overview: last 3 visits, last 3 files, last 3 of each category.
     */
    public function overview($patientId)
    {
        $patient = Patient::findOrFail($patientId);

        // Last 3 visits only
        $recentVisits = PatientVisit::where('patient_id', $patientId)
            ->orderBy('visit_date', 'desc')
            ->orderBy('visit_time', 'desc')
            ->limit(3)
            ->get()
            ->map(fn($v) => $this->formatVisit($v));

        // Last 3 files overall
        $recentFiles = PatientFile::where('patient_id', $patientId)
            ->orderBy('id', 'desc')
            ->limit(3)
            ->get();

        // Last 3 files per category (for dedicated section previews)
        $categories = PatientFile::where('patient_id', $patientId)
            ->select('category')
            ->distinct()
            ->whereNotNull('category')
            ->pluck('category');

        $categoryPreviews = [];
        foreach ($categories as $cat) {
            $categoryPreviews[$cat] = PatientFile::where('patient_id', $patientId)
                ->where('category', $cat)
                ->orderBy('id', 'desc')
                ->limit(3)
                ->get();
        }

        // Counts for UI badges
        $visitCount = PatientVisit::where('patient_id', $patientId)->count();
        $fileCount = PatientFile::where('patient_id', $patientId)->count();
        $categoryCounts = [];
        foreach ($categories as $cat) {
            $categoryCounts[$cat] = PatientFile::where('patient_id', $patientId)
                ->where('category', $cat)
                ->count();
        }

        return response()->json([
            'patient' => [
                'id' => $patient->id,
                'code' => $patient->code,
                'name' => $patient->name,
                'phone' => $patient->phone,
                'address' => $patient->address,
                'diagnosis' => $patient->diagnosis,
            ],
            'recent_visits' => $recentVisits,
            'recent_files' => $recentFiles,
            'category_previews' => $categoryPreviews,
            'counts' => [
                'visits' => $visitCount,
                'files' => $fileCount,
                'categories' => $categoryCounts,
            ],
        ]);
    }

    /**
     * GET /api/patients/{id}/visits/paginated
     * Server-side paginated visits for dedicated view.
     */
    public function visitsPaginated(Request $request, $patientId)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = min(max($perPage, 5), 50);

        $visits = PatientVisit::where('patient_id', $patientId)
            ->orderBy('visit_date', 'desc')
            ->orderBy('visit_time', 'desc')
            ->paginate($perPage);

        $visits->getCollection()->transform(fn($v) => $this->formatVisit($v));

        return response()->json($visits);
    }

    /**
     * GET /api/patients/{id}/files/paginated
     * Server-side paginated files for dedicated view.
     */
    public function filesPaginated(Request $request, $patientId)
    {
        $perPage = (int) $request->get('per_page', 12);
        $perPage = min(max($perPage, 6), 50);
        $category = $request->get('category');
        $type = $request->get('type');
        $query = $request->get('q');

        $builder = PatientFile::where('patient_id', $patientId)
            ->orderBy('id', 'desc');

        if ($category && $category !== 'all') {
            $builder->where('category', $category);
        }

        if ($type && $type !== 'all') {
            $builder->where('type', 'like', "%{$type}%");
        }

        if ($query) {
            $builder->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                  ->orWhere('desc', 'like', "%{$query}%");
            });
        }

        $files = $builder->paginate($perPage);

        return response()->json($files);
    }

    /**
     * GET /api/patients/{id}/files/by-category
     * Returns paginated files grouped by category for dedicated category view.
     */
    public function filesByCategory(Request $request, $patientId)
    {
        $category = $request->get('category');
        $perPage = (int) $request->get('per_page', 12);
        $perPage = min(max($perPage, 6), 50);

        $builder = PatientFile::where('patient_id', $patientId)
            ->orderBy('id', 'desc');

        if ($category && $category !== 'all') {
            $builder->where('category', $category);
        }

        $files = $builder->paginate($perPage);

        return response()->json([
            'category' => $category,
            'files' => $files->items(),
            'pagination' => [
                'current_page' => $files->currentPage(),
                'last_page' => $files->lastPage(),
                'per_page' => $files->perPage(),
                'total' => $files->total(),
                'from' => $files->firstItem(),
                'to' => $files->lastItem(),
            ],
        ]);
    }

    private function formatVisit(PatientVisit $v): array
    {
        return [
            'id' => $v->id,
            'visit_type' => $v->visit_type,
            'visit_type_custom' => $v->visit_type_custom,
            'visit_type_label' => $v->visit_type_label,
            'reason' => $v->reason,
            'reason_custom' => $v->reason_custom,
            'reason_label' => $v->reason_label,
            'visit_date' => $v->visit_date?->format('Y-m-d'),
            'visit_time' => $v->visit_time,
            'session_details' => $v->session_details ?? [],
            'diagnosis' => $v->diagnosis,
            'prescription' => $v->prescription,
            'next_visit_date' => $v->next_visit_date?->format('Y-m-d'),
            'cost' => $v->cost,
            'created_at' => $v->created_at?->format('Y-m-d H:i'),
        ];
    }
}
