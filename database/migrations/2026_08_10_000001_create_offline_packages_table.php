<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline Patient Package — lifecycle/ownership metadata only. The package
 * PAYLOAD itself lives in the existing patients/patient_notes/patient_visits/
 * patient_files tables (already the right shape, shared with production);
 * this table just tracks which patients are explicitly downloaded, by whom,
 * and whether the download is safe to open offline yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_packages', function (Blueprint $table) {
            $table->id();
            $table->string('patient_uuid');
            $table->unsignedBigInteger('owner_user_id');
            // downloading -> verifying -> ready (or failed at any step)
            $table->string('status')->default('downloading');
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['patient_uuid', 'owner_user_id']);
            $table->index('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_packages');
    }
};
