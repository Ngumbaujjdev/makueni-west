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
        Schema::table('budgets', function (Blueprint $table) {
            // Add status_id (nullable initially for data migration)
            $table->foreignId('status_id')
                  ->nullable()
                  ->after('status')
                  ->constrained('statuses')
                  ->onDelete('restrict');

            // Keep old status enum temporarily for rollback safety
            // Will be removed in a future migration after data verification

            // Add deduction tracking columns
            $table->decimal('total_deductions', 15, 2)
                  ->default(0)
                  ->after('total_expense_actual');

            $table->decimal('net_income_budgeted', 15, 2)
                  ->default(0)
                  ->after('total_deductions');

            $table->decimal('net_income_actual', 15, 2)
                  ->default(0)
                  ->after('net_income_budgeted');

            // Add index for status_id
            $table->index('status_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropForeign(['status_id']);
            $table->dropIndex(['status_id']);
            $table->dropColumn([
                'status_id',
                'total_deductions',
                'net_income_budgeted',
                'net_income_actual',
            ]);
        });
    }
};
