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
        Schema::create('status_categories', function (Blueprint $table) {
            $table->id();

            // Basic Information
            $table->string('name'); // e.g., "Budget", "Donation", "Payment"
            $table->string('slug')->unique(); // e.g., "budget", "donation", "payment"
            $table->text('description')->nullable();

            // Settings
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);

            // User Tracking
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null');

            // Timestamps
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('is_active');
            $table->index('display_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('status_categories');
    }
};
