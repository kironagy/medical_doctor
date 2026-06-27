<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SyncService;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function seed(Request $request, SyncService $sync)
    {
        $page = (int) $request->query('page', 1);
        $limit = min(max((int) $request->query('limit', 100), 1), 500);

        return response()->json($sync->initialSeed($page, $limit));
    }

    public function changes(Request $request, SyncService $sync)
    {
        $request->validate([
            'since' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 100);

        return response()->json($sync->changes($request->query('since'), $page, $limit));
    }

    public function push(Request $request, SyncService $sync)
    {
        $data = $request->validate([
            'operations' => ['required', 'array'],
            'operations.*.uuid' => ['nullable', 'uuid'],
            'operations.*.record_uuid' => ['nullable', 'uuid'],
            'operations.*.table' => ['required', 'string'],
            'operations.*.operation' => ['required', 'string', 'in:create,update,delete'],
            'operations.*.payload' => ['nullable', 'array'],
        ]);

        return response()->json([
            'server_time' => now()->toISOString(),
            'results' => $sync->applyOperations($data['operations']),
        ]);
    }
}
