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
        Schema::create('budget_line_items', function (Blueprint $table) {
            $table->id();

            // Parent Budget
            $table->foreignId('budget_id')
                  ->constrained('budgets')
                  ->onDelete('cascade');

            // Budget Line (Template)
            $table->foreignId('budget_line_id')
                  ->constrained('budget_lines')
                  ->onDelete('restrict');

            // Budget Category (for quick filtering - denormalized for performance)
            $table->foreignId('budget_category_id')
                  ->constrained('budget_categories')
                  ->onDelete('restrict');

            // Amounts
            $table->decimal('budgeted_amount', 15, 2)->default(0);
            $table->decimal('actual_amount', 15, 2)->default(0);

            // Notes and Locking
            $table->text('notes')->nullable();
            $table->boolean('is_locked')->default(false); // Lock after budget approval

            // User Tracking
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null');
            $table->foreignId('updated_by')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null');

            // Timestamps
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index('budget_id');
            $table->index('budget_line_id');
            $table->index('budget_category_id');

            // Unique constraint: One budget line per budget
            $table->unique(['budget_id', 'budget_line_id'], 'unique_budget_line_per_budget');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_line_items');
    }
};
