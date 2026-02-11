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
        Schema::create('budget_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_type_id')->constrained('budget_types')->onDelete('cascade');
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->onDelete('cascade');
            $table->foreignId('fiscal_month_id')->nullable()->constrained('fiscal_months')->onDelete('set null');
            $table->foreignId('fiscal_quarter_id')->nullable()->constrained('fiscal_quarters')->onDelete('set null');
            $table->foreignId('fiscal_semi_annual_id')->nullable()->constrained('fiscal_semi_annuals')->onDelete('set null');
            $table->string('name'); // "January 2026", "Q1 2026", "H1 2026", "Year 2026"
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            // Unique constraint: one period per type per year per period number
            $table->unique(['budget_type_id', 'fiscal_year_id', 'fiscal_month_id'], 'bp_type_year_month_unique');
            $table->unique(['budget_type_id', 'fiscal_year_id', 'fiscal_quarter_id'], 'bp_type_year_quarter_unique');
            $table->unique(['budget_type_id', 'fiscal_year_id', 'fiscal_semi_annual_id'], 'bp_type_year_semi_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_periods');
    }
};
