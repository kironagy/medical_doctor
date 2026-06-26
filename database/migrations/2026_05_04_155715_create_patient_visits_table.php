<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();

            // Visit type: كشف / متابعة / عملية / طوارئ / غيره
            $table->string('visit_type');          // e.g. "كشف", "متابعة", "عملية"
            $table->string('visit_type_custom')->nullable(); // custom if "غيره"

            // Reason / chief complaint
            $table->string('reason');              // السبب (select or custom)
            $table->string('reason_custom')->nullable();

            // Visit date & time
            $table->date('visit_date');
            $table->time('visit_time')->nullable();

            // Session details (checkboxes / multi-values stored as JSON)
            $table->json('session_details')->nullable(); // ["أشعة","تحاليل","عملية",...]

            // Doctor notes / diagnosis
            $table->text('diagnosis')->nullable();        // التشخيص / ملاحظات الطبيب

            // Prescription / medication
            $table->text('prescription')->nullable();     // الروشتة / العلاج

            // Next appointment
            $table->date('next_visit_date')->nullable();

            // Cost
            $table->decimal('cost', 8, 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_visits');
    }
};
