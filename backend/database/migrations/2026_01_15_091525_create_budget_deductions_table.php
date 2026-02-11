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
        Schema::create('budget_deductions', function (Blueprint $table) {
            $table->id();
            
            // Deduction details
            $table->string('name'); // e.g., "Tax Withholding", "Diocesan Levy"
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            
            // Deduction calculation
            $table->enum('deduction_type', ['percentage', 'fixed_amount']);
            $table->decimal('deduction_value', 15, 2); // Percentage or fixed amount
            
            // Applicability
            $table->enum('applies_to', ['income', 'expense', 'both'])->default('income');
            $table->enum('territory_scope', ['diocese', 'region', 'subregion', 'church', 'all'])->default('all');
            
            // Settings
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            
            // User tracking
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_deductions');
    }
};
