<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('statuses', function (Blueprint $table) {
            $table->id();

            // Category Reference
            $table->foreignId('status_category_id')
                  ->constrained('status_categories')
                  ->onDelete('restrict');

            // Basic Information
            $table->string('name'); // e.g., "Draft", "Approved"
            $table->string('slug'); // e.g., "draft", "approved"
            $table->text('description')->nullable();

            // UI Styling
            $table->string('color', 7)->default('#3B82F6'); // Hex color code
            $table->string('icon')->nullable(); // Icon class (e.g., "ri-draft-line")

            // Ordering and Behavior
            $table->integer('display_order')->default(0);
            $table->boolean('is_initial')->default(false); // Default/initial status
            $table->boolean('is_final')->default(false); // Terminal status (no further changes)
            $table->boolean('is_active')->default(true);

            // User Tracking
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null');

            // Timestamps
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->unique(['status_category_id', 'slug']); // Unique slug within category
            $table->index('status_category_id');
            $table->index('is_active');
            $table->index('display_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('statuses');
    }
};
