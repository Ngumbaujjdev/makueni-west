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
        Schema::create('budget_logs', function (Blueprint $table) {
            $table->id();
            
            // Budget reference
            $table->foreignId('budget_id')->constrained('budgets')->onDelete('cascade');
            
            // Action details
            $table->enum('action', [
                'created', 'updated', 'deleted',
                'status_changed',
                'deduction_applied', 'deduction_reversed',
                'line_item_added', 'line_item_updated', 'line_item_deleted',
                'submitted', 'approved', 'rejected', 'activated', 'closed'
            ]);
            $table->text('description'); // Human-readable description
            
            // Change tracking
            $table->json('old_values')->nullable(); // Previous values
            $table->json('new_values')->nullable(); // New values
            
            // Affected model tracking
            $table->string('affected_model')->nullable(); // e.g., "BudgetLineItem"
            $table->unsignedBigInteger('affected_model_id')->nullable();
            
            // Financial impact
            $table->decimal('amount_change', 15, 2)->nullable(); // Positive or negative
            
            // User tracking
            $table->foreignId('performed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('notes')->nullable(); // Optional notes/reason
            
            // Reversal tracking
            $table->boolean('is_reversal')->default(false);
            $table->foreignId('reversal_of_log_id')->nullable()->constrained('budget_logs')->onDelete('set null');
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index('budget_id');
            $table->index('action');
            $table->index('performed_by');
            $table->index('created_at');
            $table->index('is_reversal');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_logs');
    }
};
