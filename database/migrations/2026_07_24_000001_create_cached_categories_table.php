<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the cached_categories table for offline category support.
     * Categories are cached per-user so that the app works fully offline.
     *
     * Schema:
     *   - user_id:   The doctor this category belongs to
     *   - slug:      Unique category slug (e.g. 'medical_history')
     *   - name:      Display name (Arabic/English)
     *   - icon:      Icon identifier for the UI
     *   - color:     Hex color code
     *   - sort_order: Display order
     *   - is_visible: Whether the category is shown in the UI
     *
     * The UNIQUE(user_id, slug) constraint ensures idempotent upserts
     * when refreshing the cache — no duplicates when re-fetching.
     */
    public function up(): void
    {
        Schema::create('cached_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('slug');
            $table->string('name');
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->integer('sort_order')->default(99);
            $table->boolean('is_visible')->default(true);
            $table->timestamp('client_updated_at')->nullable();
            $table->timestamps();

            // Prevent duplicates when refreshing the cache
            $table->unique(['user_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cached_categories');
    }
};
