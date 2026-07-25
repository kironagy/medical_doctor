<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Makes author_id nullable in patient_notes so that notes created
     * offline (without an authenticated user) can be saved. The foreign
     * key is changed from cascadeOnDelete to nullOnDelete so notes
     * survive even if the authoring user is later removed.
     */
    public function up(): void
    {
        Schema::table('patient_notes', function (Blueprint $table) {
            // Drop the existing foreign key
            $table->dropForeign(['author_id']);

            // Make the column nullable
            $table->unsignedBigInteger('author_id')->nullable()->change();

            // Re-add foreign key with nullOnDelete
            $table->foreign('author_id')
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
        Schema::table('patient_notes', function (Blueprint $table) {
            // Drop the nullable foreign key
            $table->dropForeign(['author_id']);

            // Restore NOT NULL constraint
            $table->unsignedBigInteger('author_id')->nullable(false)->change();

            // Re-add original foreign key with cascadeOnDelete
            $table->foreign('author_id')
                  ->references('id')
                  ->on('users')
                  ->cascadeOnDelete();
        });
    }
};
