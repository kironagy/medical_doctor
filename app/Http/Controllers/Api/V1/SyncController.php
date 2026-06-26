<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SyncService;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function seed(SyncService $sync)
    {
        return response()->json($sync->initialSeed());
    }

    public function changes(Request $request, SyncService $sync)
    {
        $request->validate([
            'since' => ['nullable', 'date'],
        ]);

        return response()->json($sync->changes($request->query('since')));
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
