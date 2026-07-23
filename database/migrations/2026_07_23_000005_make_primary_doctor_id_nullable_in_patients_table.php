<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Makes primary_doctor_id nullable in the patients table so that
     * offline patient creation (without an authenticated user) can succeed.
     * The foreign key constraint is changed from cascadeOnDelete to nullOnDelete
     * so patients are preserved even if the referencing user is removed.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            // Drop the existing foreign key (SQLite requires explicit constraint name)
            $table->dropForeign(['primary_doctor_id']);

            // Make the column nullable
            $table->unsignedBigInteger('primary_doctor_id')->nullable()->change();

            // Re-add foreign key with nullOnDelete so patients survive doctor removal
            $table->foreign('primary_doctor_id')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            // Drop the nullable foreign key
            $table->dropForeign(['primary_doctor_id']);

            // Restore NOT NULL constraint
            $table->unsignedBigInteger('primary_doctor_id')->nullable(false)->change();

            // Re-add original foreign key with cascadeOnDelete
            $table->foreign('primary_doctor_id')
                  ->references('id')
                  ->on('users')
                  ->cascadeOnDelete();
        });
    }
};
