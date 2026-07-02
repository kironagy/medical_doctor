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
    ];

    public function ensureInitialized(): void
    {
        $logger = Log::channel('mobile-api');

        $logger->info('[SQLite Initializer] Starting initialization check...');

        $databasePath = database_path('database.sqlite');

        if (!File::exists($databasePath)) {
            $logger->info('[SQLite Initializer] Creating database file...');
            File::put($databasePath, '');
        }

        // Check if tables exist
        if ($this->tablesExist()) {
            $logger->info('[SQLite Initializer] Tables already exist, skipping...');
            return;
        }

        $logger->info('[SQLite Initializer] Running migrations...');
        Artisan::call('migrate', ['--force' => true]);
        $logger->info('[SQLite Initializer] Migrations complete. Output: ' . Artisan::output());

        // Seed data
        $logger->info('[SQLite Initializer] Running seeders...');
        $this->seedData();
        $logger->info('[SQLite Initializer] Seeding complete!');
    }

    public function tablesExist(): bool
    {
        $existingTables = DB::select('SELECT name FROM sqlite_master WHERE type = "table"');
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
        // Run DatabaseSeeder first
        Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);

        // Then run MobileDemoSeeder
        Artisan::call('db:seed', ['--class' => 'MobileDemoSeeder', '--force' => true]);
    }
}
