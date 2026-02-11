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
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();

            // Budget Type (Annual, Quarterly, Monthly)
            $table->foreignId('budget_type_id')
                  ->constrained('budget_types')
                  ->onDelete('restrict');

            // Territory Information (Polymorphic - can be Diocese, Region, SubRegion, or Church)
            $table->enum('territory_type', ['diocese', 'region', 'subregion', 'church']);
            $table->unsignedBigInteger('territory_id');
            $table->index(['territory_type', 'territory_id']);

            // Budget Details
            $table->string('name'); // e.g., "Diocese Annual Budget 2026"
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->year('fiscal_year'); // e.g., 2026
            $table->date('start_date');
            $table->date('end_date');

            // Status Workflow
            $table->enum('status', [
                'draft',
                'submitted',
                'under_review',
                'approved',
                'rejected',
                'active',
                'closed'
            ])->default('draft');

            // Financial Totals (auto-calculated from line items)
            $table->decimal('total_income_budgeted', 15, 2)->default(0);
            $table->decimal('total_expense_budgeted', 15, 2)->default(0);
            $table->decimal('total_income_actual', 15, 2)->default(0);
            $table->decimal('total_expense_actual', 15, 2)->default(0);

            // Approval Tracking
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null');
            $table->text('approval_notes')->nullable();
            $table->text('rejection_reason')->nullable();

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
            $table->index('fiscal_year');
            $table->index('status');
            $table->index('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
