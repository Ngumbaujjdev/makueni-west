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
        Schema::create('budget_deduction_items', function (Blueprint $table) {
            $table->id();
            
            // References
            $table->foreignId('budget_id')->constrained('budgets')->onDelete('cascade');
            $table->foreignId('budget_deduction_id')->constrained('budget_deductions')->onDelete('restrict');
            
            // Deduction amount
            $table->decimal('deduction_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            
            // Application status
            $table->boolean('is_applied')->default(false);
            $table->timestamp('applied_at')->nullable();
            
            // Reversal tracking
            $table->boolean('is_reversed')->default(false);
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('reversal_reason')->nullable();
            
            // User tracking
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Unique constraint: one deduction per budget
            $table->unique(['budget_id', 'budget_deduction_id'], 'unique_budget_deduction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_deduction_items');
    }
};
