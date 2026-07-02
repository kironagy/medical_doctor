<?php

namespace App\Domains\Mobile\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SQLiteInitializer
{
    const REQUIRED_TABLES = [
        'users',
        'patients',
        'patient_files',
        'patient_notes',
        'file_categories',
        'patient_visits',
        'patient_shares',
        'cache',
    ];

    public function ensureInitialized(): void
    {
        $logger = Log::channel('mobile-api');

        $logger->info('=== SQLITE INITIALIZER START ===');

        $databasePath = database_path('database.sqlite');
        $logger->info('Checking database file', ['path' => $databasePath, 'exists' => File::exists($databasePath)]);

        if (!File::exists($databasePath)) {
            $logger->info('Creating database file...');
            File::put($databasePath, '');
            $logger->info('Database file created successfully');
        }

        $logger->info('Checking required tables...');
        $existingTables = DB::connection('sqlite')->select('SELECT name FROM sqlite_master WHERE type = "table"');
        $existingTableNames = array_map(fn($t) => $t->name, $existingTables);
        $logger->info('Existing tables', ['tables' => $existingTableNames]);

        // Check if tables exist
        $allTablesExist = true;
        foreach (self::REQUIRED_TABLES as $table) {
            if (!in_array($table, $existingTableNames)) {
                $allTablesExist = false;
                $logger->info('Table missing', ['table' => $table]);
                break;
            }
        }

        if ($allTablesExist) {
            $logger->info('All required tables exist, skipping initialization');
            return;
        }

        $logger->info('Running migrations...');
        $exitCode = Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $logger->info('Migrations complete', ['exit_code' => $exitCode, 'output' => Artisan::output()]);

        // Seed data
        $logger->info('Running seeders...');
        $this->seedData();
        $logger->info('=== SQLITE INITIALIZER COMPLETE ===');
    }

    public function tablesExist(): bool
    {
        $existingTables = DB::connection('sqlite')->select('SELECT name FROM sqlite_master WHERE type = "table"');
        $existingTableNames = array_map(fn($t) => $t->name, $existingTables);

        foreach (self::REQUIRED_TABLES as $table) {
            if (!in_array($table, $existingTableNames)) {
                return false;
            }
        }

        return true;
    }

    protected function seedData(): void
    {
        $logger = Log::channel('mobile-api');
        $logger->info('Seeding DatabaseSeeder...');
        $exitCode1 = Artisan::call('db:seed', ['--database' => 'sqlite', '--class' => 'DatabaseSeeder', '--force' => true]);
        $logger->info('DatabaseSeeder complete', ['exit_code' => $exitCode1, 'output' => Artisan::output()]);

        $logger->info('Seeding MobileDemoSeeder...');
        $exitCode2 = Artisan::call('db:seed', ['--database' => 'sqlite', '--class' => 'MobileDemoSeeder', '--force' => true]);
        $logger->info('MobileDemoSeeder complete', ['exit_code' => $exitCode2, 'output' => Artisan::output()]);
    }
}
