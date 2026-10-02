<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Budgets cleanup (docs/specs/budgets-spec.md, phase 6). A budget is a month
 * or a whole year, Draft / In use / Closed, with no approval step - so the
 * approval columns, the old status row, budget type and budget period, and
 * a line item's lock are no longer used anywhere. budget_types and
 * budget_periods themselves stay: Demographics uses them.
 *
 * down() puts the columns back (empty); the old values aren't restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['status_id']);
            $table->dropForeign(['budget_type_id']);
            $table->dropForeign(['budget_period_id']);
        });
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropIndex('budgets_status_id_index');
            $table->dropColumn([
                'submitted_at', 'approved_at', 'approved_by', 'approval_notes', 'rejection_reason',
                'status_id', 'budget_type_id', 'budget_period_id',
            ]);
        });
        Schema::table('budget_line_items', function (Blueprint $table) {
            $table->dropColumn('is_locked');
        });
    }

    public function down(): void
    {
        Schema::table('budget_line_items', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false);
        });
        Schema::table('budgets', function (Blueprint $table) {
            $table->foreignId('budget_type_id')->nullable()->after('id')->constrained('budget_types')->nullOnDelete();
            $table->foreignId('budget_period_id')->nullable()->after('budget_type_id')->constrained('budget_periods')->nullOnDelete();
            $table->foreignId('status_id')->nullable()->index()->constrained('statuses')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('approval_notes')->nullable();
            $table->text('rejection_reason')->nullable();
        });
    }
};
