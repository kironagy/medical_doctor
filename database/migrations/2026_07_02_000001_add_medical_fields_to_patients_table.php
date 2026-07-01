<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            if (!Schema::hasColumn('patients', 'date_of_birth')) {
                $table->date('date_of_birth')->nullable()->after('diagnosis');
            }
            if (!Schema::hasColumn('patients', 'gender')) {
                $table->string('gender')->nullable()->after('date_of_birth');
            }
            if (!Schema::hasColumn('patients', 'blood_group')) {
                $table->string('blood_group')->nullable()->after('gender');
            }
            if (!Schema::hasColumn('patients', 'weight')) {
                $table->decimal('weight', 5, 2)->nullable()->after('blood_group');
            }
            if (!Schema::hasColumn('patients', 'height')) {
                $table->decimal('height', 5, 2)->nullable()->after('weight');
            }
            if (!Schema::hasColumn('patients', 'allergies')) {
                $table->text('allergies')->nullable()->after('height');
            }
            if (!Schema::hasColumn('patients', 'chronic_diseases')) {
                $table->text('chronic_diseases')->nullable()->after('allergies');
            }
            if (!Schema::hasColumn('patients', 'medical_status')) {
                $table->string('medical_status')->nullable()->after('chronic_diseases');
            }
            if (!Schema::hasColumn('patients', 'medical_record_number')) {
                $table->string('medical_record_number')->nullable()->after('medical_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $columns = [
                'date_of_birth', 'gender', 'blood_group', 'weight', 'height',
                'allergies', 'chronic_diseases', 'medical_status', 'medical_record_number'
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('patients', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
