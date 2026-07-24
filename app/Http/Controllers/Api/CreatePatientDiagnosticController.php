<?php

namespace App\Http\Controllers\Api;

use App\Domains\Patients\Models\Patient;
use App\Repositories\PatientRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class CreatePatientDiagnosticController extends \App\Http\Controllers\Controller
{
    public function index(Request $request, PatientRepository $patientRepo)
    {
        $results = [];

        // 1. PHP Environment
        $results['php'] = [
            'version' => PHP_VERSION,
            'extensions' => get_loaded_extensions(),
            'has_sqlite' => extension_loaded('pdo_sqlite'),
            'has_pdo' => extension_loaded('pdo'),
            'has_json' => extension_loaded('json'),
            'has_mbstring' => extension_loaded('mbstring'),
            'has_openssl' => extension_loaded('openssl'),
            'has_random' => function_exists('random_int'),
            'has_mt_rand' => function_exists('mt_rand'),
        ];

        // 2. Laravel Environment
        $results['laravel'] = [
            'env' => app()->environment(),
            'debug' => config('app.debug'),
            'version' => app()->version(),
            'db_connection' => config('database.default'),
            'db_host' => config('database.connections.' . config('database.default') . '.host', 'N/A'),
            'db_database' => config('database.connections.' . config('database.default') . '.database', 'N/A'),
        ];

        // 3. SQLite specific
        $sqliteConfig = config('database.connections.sqlite', []);
        $sqlitePath = $sqliteConfig['database'] ?? database_path('database.sqlite');
        $results['sqlite'] = [
            'config_path' => $sqlitePath,
            'file_exists' => File::exists($sqlitePath),
            'file_writable' => is_writable(dirname($sqlitePath)),
            'file_size' => File::exists($sqlitePath) ? File::size($sqlitePath) : 0,
            'dir_writable' => is_writable(dirname($sqlitePath)),
        ];

        // 4. Database connection test
        try {
            DB::connection()->getPdo();
            $results['db_connection'] = 'OK';
        } catch (\Throwable $e) {
            $results['db_connection'] = 'FAILED: ' . $e->getMessage();
        }

        // 5. Patients table schema
        try {
            $columns = Schema::getColumnListing('patients');
            $results['patients_table'] = [
                'exists' => true,
                'columns' => $columns,
                'column_count' => count($columns),
            ];
            $keyColumns = ['uuid', 'name', 'phone', 'sync_status', 'primary_doctor_id', 'created_by_id', 'client_updated_at'];
            foreach ($keyColumns as $col) {
                $results['patients_table']['has_' . $col] = in_array($col, $columns);
            }
        } catch (\Throwable $e) {
            $results['patients_table'] = [
                'exists' => false,
                'error' => $e->getMessage(),
            ];
        }

        // 6. Patient model fillable
        $model = new Patient();
        $results['patient_model'] = [
            'fillable' => $model->getFillable(),
            'table' => $model->getTable(),
            'connection' => $model->getConnectionName(),
            'casts' => $model->getCasts(),
        ];

        // 7. Test random_int / mt_rand
        try {
            $code = (string) random_int(100000, 999999);
            $results['random_int'] = 'OK: ' . $code;
        } catch (\Throwable $e) {
            try {
                $code = (string) mt_rand(100000, 999999);
                $results['random_int'] = 'mt_rand fallback OK: ' . $code . ' (random_int failed: ' . $e->getMessage() . ')';
            } catch (\Throwable $e2) {
                $results['random_int'] = 'BOTH FAILED: ' . $e->getMessage() . ' | ' . $e2->getMessage();
            }
        }

        // 8. Test Patient::create (with rollback)
        try {
            DB::beginTransaction();
            $testCode = '99999' . random_int(0, 99999);
            $testPatient = Patient::create([
                'name' => 'DIAG_TEST_PATIENT',
                'code' => $testCode,
                'phone' => '+0000000000',
                'sync_status' => 'pending_create',
                'client_updated_at' => now(),
            ]);
            $results['test_create'] = [
                'success' => true,
                'uuid' => $testPatient->uuid,
                'id' => $testPatient->id,
            ];
            $readBack = Patient::where('uuid', $testPatient->uuid)->first();
            $results['test_create']['read_back'] = $readBack ? 'OK' : 'NOT FOUND';
            $testPatient->forceDelete();
            DB::rollBack();
        } catch (\Throwable $e) {
            try { DB::rollBack(); } catch (\Throwable $ignore) {}
            $results['test_create'] = [
                'success' => false,
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'class' => get_class($e),
            ];
        }

        // 9. Full create via PatientRepository (test with rollback)
        try {
            DB::beginTransaction();
            $testCode2 = '88888' . random_int(0, 99999);
            $testData = [
                'name' => 'DIAG_REPO_TEST',
                'phone' => '+0000000000',
                'code' => $testCode2,
            ];
            $result = $patientRepo->create($testData);
            $results['repo_create'] = [
                'success' => isset($result['uuid']),
                'uuid' => $result['uuid'] ?? 'NONE',
                'sync_status' => $result['sync_status'] ?? 'NONE',
                'has_keys' => array_keys($result),
            ];
            if (isset($result['uuid'])) {
                Patient::where('uuid', $result['uuid'])->forceDelete();
            }
            DB::rollBack();
        } catch (\Throwable $e) {
            try { DB::rollBack(); } catch (\Throwable $ignore) {}
            $results['repo_create'] = [
                'success' => false,
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'class' => get_class($e),
            ];
        }

        // 10. Session status
        try {
            $results['session'] = [
                'id' => session()->getId(),
                'has_api_token' => session()->has('api_token'),
                'has_auth_credentials' => session()->has('auth_credentials'),
                'has_user' => auth()->check(),
                'user_id' => auth()->id(),
                'driver' => config('session.driver'),
            ];
        } catch (\Throwable $e) {
            $results['session'] = ['error' => $e->getMessage()];
        }

        // 11. Route registration
        $routeExists = Route::has('api.v1.workspace.patients.store');
        if (!$routeExists) {
            $results['routes'] = [];
            foreach (Route::getRoutes() as $route) {
                if (str_contains($route->uri(), 'workspace/patients')) {
                    $results['routes'][] = [
                        'uri' => $route->uri(),
                        'methods' => $route->methods(),
                        'name' => $route->getName(),
                        'action' => $route->getActionName(),
                    ];
                }
            }
        }

        // 12. Controller method test
        try {
            app()->make(\App\Http\Controllers\WorkspaceController::class);
            $results['controller_resolved'] = 'OK';
        } catch (\Throwable $e) {
            $results['controller_resolved'] = 'FAILED: ' . $e->getMessage();
        }

        return response()->json([
            'success' => true,
            'timestamp' => now()->toIso8601String(),
            'results' => $results,
        ]);
    }
}
