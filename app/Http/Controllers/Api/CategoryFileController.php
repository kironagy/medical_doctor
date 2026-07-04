<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use App\Http\Controllers\Controller;
use App\Services\ApiProxy;
use App\Services\NetworkStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CategoryFileController extends Controller
{
    public function files(Request $request, string $patientUuid, string $slug)
    {
        if (ApiProxy::isEnabled() && NetworkStatusService::isOnline()) {
            try {
                app(PatientRepositoryInterface::class)->findByUuid($patientUuid);
                app(PatientFileRepositoryInterface::class)->forPatient($patientUuid);
            } catch (\Throwable $e) {
                Log::warning('[CategoryFileController] Remote sync failed, using local cache', [
                    'patient_uuid' => $patientUuid,
                    'category' => $slug,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();

        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 6);
        $search = $request->input('search');
        $sort = $request->input('sort', 'newest');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $timeFrom = $request->input('time_from');
        $timeTo = $request->input('time_to');

        if ($perPage < 1 || $perPage > 100) $perPage = 6;
        if ($page < 1) $page = 1;

        $fileQuery = PatientFile::where('patient_id', $patient->id)
            ->where('category', $slug);

        // Date filtering
        if ($dateFrom && $dateTo) {
            $fileQuery->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        } elseif ($dateFrom) {
            $fileQuery->whereDate('created_at', '>=', $dateFrom);
        } elseif ($dateTo) {
            $fileQuery->whereDate('created_at', '<=', $dateTo);
        }

        // Time filtering
        if ($timeFrom && $timeTo) {
            $fileQuery->whereTime('created_at', '>=', $timeFrom)
                ->whereTime('created_at', '<=', $timeTo);
        } elseif ($timeFrom) {
            $fileQuery->whereTime('created_at', '>=', $timeFrom);
        } elseif ($timeTo) {
            $fileQuery->whereTime('created_at', '<=', $timeTo);
        }

        // Search
        if ($search && strlen(trim($search)) > 0) {
            $q = trim($search);
            $fileQuery->where(function ($query) use ($q) {
                $query->where('title', 'like', "%{$q}%")
                    ->orWhere('desc', 'like', "%{$q}%")
                    ->orWhere('file_name', 'like', "%{$q}%")
                    ->orWhere('mime_type', 'like', "%{$q}%")
                    ->orWhere('type', 'like', "%{$q}%")
                    ->orWhere('category', 'like', "%{$q}%")
                    ->orWhereHas('uploader', function ($qry) use ($q) {
                        $qry->where('name', 'like', "%{$q}%");
                    });
            });
        }

        // Sorting
        switch ($sort) {
            case 'oldest':
                $fileQuery->orderBy('created_at', 'asc');
                break;
            case 'name_asc':
                $fileQuery->orderBy('file_name', 'asc');
                break;
            case 'name_desc':
                $fileQuery->orderBy('file_name', 'desc');
                break;
            case 'largest':
                $fileQuery->orderBy('size', 'desc');
                break;
            case 'smallest':
                $fileQuery->orderBy('size', 'asc');
                break;
            case 'recently_updated':
                $fileQuery->orderBy('updated_at', 'desc');
                break;
            case 'newest':
            default:
                $fileQuery->orderBy('created_at', 'desc');
                break;
        }

        $files = $fileQuery->with('uploader:id,name')->paginate($perPage, ['*'], 'page', $page);

        // Notes search (not paginated - typically few per category)
        $noteQuery = PatientNote::where('patient_id', $patient->id)
            ->where('category', $slug);

        if ($search && strlen(trim($search)) > 0) {
            $q = trim($search);
            $noteQuery->where(function ($query) use ($q) {
                $query->where('content', 'like', "%{$q}%")
                    ->orWhere('category', 'like', "%{$q}%");
            });
        }

        // Apply same date filter to notes
        if ($dateFrom && $dateTo) {
            $noteQuery->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        } elseif ($dateFrom) {
            $noteQuery->whereDate('created_at', '>=', $dateFrom);
        } elseif ($dateTo) {
            $noteQuery->whereDate('created_at', '<=', $dateTo);
        }

        $notes = $noteQuery->with('author:id,name,email')->latest()->get();

        return response()->json([
            'data' => $files->items(),
            'meta' => [
                'current_page' => $files->currentPage(),
                'last_page' => $files->lastPage(),
                'per_page' => $files->perPage(),
                'total' => $files->total(),
                'from' => $files->firstItem(),
                'to' => $files->lastItem(),
            ],
            'notes' => $notes,
            'notes_count' => $notes->count(),
        ]);
    }
}
