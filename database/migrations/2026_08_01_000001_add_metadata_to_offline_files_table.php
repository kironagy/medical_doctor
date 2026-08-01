<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds title, desc, category, and date metadata columns to offline_files
     * so that files uploaded offline retain their category, description, and
     * title when synchronized to the production server.
     */
    public function up(): void
    {
        Schema::table('offline_files', function (Blueprint $table) {
            if (!Schema::hasColumn('offline_files', 'title')) {
                $table->string('title')->nullable()->after('original_name');
            }
            if (!Schema::hasColumn('offline_files', 'desc')) {
                $table->text('desc')->nullable()->after('title');
            }
            if (!Schema::hasColumn('offline_files', 'category')) {
                $table->string('category', 100)->nullable()->after('desc');
            }
            if (!Schema::hasColumn('offline_files', 'date')) {
                $table->date('date')->nullable()->after('category');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offline_files', function (Blueprint $table) {
            $table->dropColumn(['title', 'desc', 'category', 'date']);
        });
    }
};
