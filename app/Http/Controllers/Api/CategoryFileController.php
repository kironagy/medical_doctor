<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Domains\Auth\Scopes\DoctorIsolationScope;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use App\Http\Controllers\Controller;
use App\Services\NetworkStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CategoryFileController extends Controller
{
    public function __construct(
        private readonly PatientRepositoryInterface $patientRepo,
        private readonly PatientFileRepositoryInterface $fileRepo,
    ) {}

    public function files(Request $request, string $patientUuid, string $slug)
    {
        $startTime = microtime(true);
        $isOnline = NetworkStatusService::isOnline();
        $source = $isOnline ? 'repository' : 'sqlite';

        Log::info('[CategoryFileController] Request started', [
            'patient_uuid' => $patientUuid,
            'category' => $slug,
            'online' => $isOnline,
            'source' => $source,
        ]);

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

        if ($isOnline) {
            try {
                // Use Repository only - this fetches from Production API and caches locally
                $patient = $this->patientRepo->findByUuid($patientUuid);
                $allFiles = $this->fileRepo->forPatient($patientUuid);

                Log::info('[CategoryFileController] Repository returned data', [
                    'patient_uuid' => $patientUuid,
                    'category' => $slug,
                    'total_files' => count($allFiles),
                    'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                ]);

                // Filter by category
                $categoryFiles = array_values(array_filter($allFiles, function ($f) use ($slug) {
                    return ($f['category'] ?? '') === $slug;
                }));

                // Sort
                $categoryFiles = $this->sortFiles($categoryFiles, $sort);

                // Search
                if ($search && strlen(trim($search)) > 0) {
                    $q = strtolower(trim($search));
                    $categoryFiles = array_values(array_filter($categoryFiles, function ($f) use ($q) {
                        return stripos($f['title'] ?? '', $q) !== false
                            || stripos($f['file_name'] ?? '', $q) !== false
                            || stripos($f['desc'] ?? '', $q) !== false
                            || stripos($f['mime_type'] ?? '', $q) !== false
                            || stripos($f['type'] ?? '', $q) !== false;
                    }));
                }

                // Date filter
                if ($dateFrom) {
                    $categoryFiles = array_values(array_filter($categoryFiles, function ($f) use ($dateFrom) {
                        return ($f['created_at'] ?? '') >= $dateFrom;
                    }));
                }
                if ($dateTo) {
                    $categoryFiles = array_values(array_filter($categoryFiles, function ($f) use ($dateTo) {
                        return ($f['created_at'] ?? '') <= $dateTo . ' 23:59:59';
                    }));
                }

                $total = count($categoryFiles);
                $lastPage = max(1, (int) ceil($total / $perPage));
                $offset = ($page - 1) * $perPage;
                $paginatedFiles = array_slice($categoryFiles, $offset, $perPage);

                // Get notes from repository (filtered by category)
                $notes = $this->getNotesFromRepo($patientUuid, $slug, $search, $dateFrom, $dateTo);

                Log::info('[CategoryFileController] Response prepared', [
                    'patient_uuid' => $patientUuid,
                    'category' => $slug,
                    'source' => 'repository',
                    'total_files' => $total,
                    'returned_files' => count($paginatedFiles),
                    'total_notes' => count($notes),
                    'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                ]);

                return response()->json([
                    'data' => $paginatedFiles,
                    'meta' => [
                        'current_page' => $page,
                        'last_page' => $lastPage,
                        'per_page' => $perPage,
                        'total' => $total,
                        'from' => $offset + 1,
                        'to' => min($offset + $perPage, $total),
                    ],
                    'notes' => $notes,
                    'notes_count' => count($notes),
                ]);
            } catch (\Throwable $e) {
                Log::error('[CategoryFileController] Repository failed, falling back to SQLite', [
                    'patient_uuid' => $patientUuid,
                    'category' => $slug,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                NetworkStatusService::setOnline(false);
                // Fall through to SQLite
            }
        }

        // OFFLINE: Use SQLite directly
        Log::info('[CategoryFileController] Using SQLite', [
            'patient_uuid' => $patientUuid,
            'category' => $slug,
        ]);

        $patient = Patient::withoutGlobalScope(DoctorIsolationScope::class)
            ->where('uuid', $patientUuid)->first();

        if (!$patient) {
            Log::warning('[CategoryFileController] Patient not found in SQLite', [
                'patient_uuid' => $patientUuid,
            ]);
            return response()->json(['data' => [], 'meta' => [
                'current_page' => 1, 'last_page' => 1, 'per_page' => $perPage,
                'total' => 0, 'from' => null, 'to' => null,
            ], 'notes' => [], 'notes_count' => 0]);
        }

        $fileQuery = PatientFile::withoutGlobalScope(DoctorIsolationScope::class)
            ->where('patient_id', $patient->id)
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
                    ->orWhere('type', 'like', "%{$q}%");
            });
        }

        // Sorting
        $this->applySort($fileQuery, $sort);

        $files = $fileQuery->with('uploader:id,name')->paginate($perPage, ['*'], 'page', $page);

        // Notes
        $noteQuery = PatientNote::withoutGlobalScope(DoctorIsolationScope::class)
            ->where('patient_id', $patient->id)
            ->where('category', $slug);

        if ($search && strlen(trim($search)) > 0) {
            $q = trim($search);
            $noteQuery->where(function ($query) use ($q) {
                $query->where('content', 'like', "%{$q}%");
            });
        }

        if ($dateFrom && $dateTo) {
            $noteQuery->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        } elseif ($dateFrom) {
            $noteQuery->whereDate('created_at', '>=', $dateFrom);
        } elseif ($dateTo) {
            $noteQuery->whereDate('created_at', '<=', $dateTo);
        }

        $notes = $noteQuery->with('author:id,name,email')->latest()->get();

        // Use FileResource for proper URL generation
        $fileData = \App\Domains\Media\Resources\FileResource::collection($files);

        Log::info('[CategoryFileController] SQLite response prepared', [
            'patient_uuid' => $patientUuid,
            'category' => $slug,
            'source' => 'sqlite',
            'total_files' => $files->total(),
            'total_notes' => $notes->count(),
            'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
        ]);

        return response()->json([
            'data' => $fileData->resolve($request),
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

    private function sortFiles(array $files, string $sort): array
    {
        usort($files, function ($a, $b) use ($sort) {
            return match ($sort) {
                'oldest' => strcmp($a['created_at'] ?? '', $b['created_at'] ?? ''),
                'name_asc' => strcmp($a['file_name'] ?? '', $b['file_name'] ?? ''),
                'name_desc' => strcmp($b['file_name'] ?? '', $a['file_name'] ?? ''),
                'largest' => ($b['size'] ?? 0) - ($a['size'] ?? 0),
                'smallest' => ($a['size'] ?? 0) - ($b['size'] ?? 0),
                'recently_updated' => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''),
                default => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''),
            };
        });
        return $files;
    }

    private function applySort($query, string $sort): void
    {
        switch ($sort) {
            case 'oldest':
                $query->orderBy('created_at', 'asc');
                break;
            case 'name_asc':
                $query->orderBy('file_name', 'asc');
                break;
            case 'name_desc':
                $query->orderBy('file_name', 'desc');
                break;
            case 'largest':
                $query->orderBy('size', 'desc');
                break;
            case 'smallest':
                $query->orderBy('size', 'asc');
                break;
            case 'recently_updated':
                $query->orderBy('updated_at', 'desc');
                break;
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }
    }

    private function getNotesFromRepo(string $patientUuid, string $slug, ?string $search, ?string $dateFrom, ?string $dateTo): array
    {
        try {
            $noteRepo = app(\App\Contracts\Repositories\PatientNoteRepositoryInterface::class);
            $allNotes = $noteRepo->forPatient($patientUuid);

            $filtered = array_values(array_filter($allNotes, function ($n) use ($slug) {
                return ($n['category'] ?? '') === $slug;
            }));

            if ($search && strlen(trim($search)) > 0) {
                $q = strtolower(trim($search));
                $filtered = array_values(array_filter($filtered, function ($n) use ($q) {
                    return stripos($n['content'] ?? '', $q) !== false;
                }));
            }

            if ($dateFrom) {
                $filtered = array_values(array_filter($filtered, function ($n) use ($dateFrom) {
                    return ($n['created_at'] ?? '') >= $dateFrom;
                }));
            }
            if ($dateTo) {
                $filtered = array_values(array_filter($filtered, function ($n) use ($dateTo) {
                    return ($n['created_at'] ?? '') <= $dateTo . ' 23:59:59';
                }));
            }

            // Sort by created_at desc
            usort($filtered, function ($a, $b) {
                return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
            });

            return $filtered;
        } catch (\Throwable $e) {
            Log::warning('[CategoryFileController] Failed to get notes from repo', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
