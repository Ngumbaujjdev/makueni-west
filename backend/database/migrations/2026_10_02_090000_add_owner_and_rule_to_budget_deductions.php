<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deductions per level (docs/specs/budgets-spec.md, phase 4): a deduction
 * now has an owner (the church, region or diocese that set it), says who it
 * applies to, what it is worked out on, and which money-out line it is paid
 * through. Budget line items remember which deduction filled them in, and
 * each budget keeps a snapshot of the rule it was worked out with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_deductions', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->string('territory_type', 20)->nullable()->after('territory_scope');
            $table->unsignedBigInteger('territory_id')->nullable()->after('territory_type');
            // own: the owner's budgets; church / region: every one below; all: the owner's and every one below
            $table->string('applies_to_level', 10)->default('own')->after('territory_id');
            $table->foreignId('budget_line_id')->nullable()->after('applies_to_level')->constrained('budget_lines')->nullOnDelete();
            // all: on all money in; lines: only on basis_line_ids
            $table->string('basis', 10)->default('all')->after('budget_line_id');
            $table->json('basis_line_ids')->nullable()->after('basis');
            $table->unique(['slug', 'territory_type', 'territory_id'], 'budget_deductions_owner_slug');
            $table->index(['territory_type', 'territory_id']);
        });

        Schema::table('budget_line_items', function (Blueprint $table) {
            $table->foreignId('budget_deduction_id')->nullable()->after('budget_line_id')->constrained('budget_deductions')->nullOnDelete();
        });

        Schema::table('budget_deduction_items', function (Blueprint $table) {
            $table->string('rate_type', 20)->nullable()->after('deduction_amount');
            $table->decimal('rate_value', 15, 2)->nullable()->after('rate_type');
            $table->decimal('base_amount', 15, 2)->nullable()->after('rate_value');
        });
    }

    public function down(): void
    {
        Schema::table('budget_deduction_items', function (Blueprint $table) {
            $table->dropColumn(['rate_type', 'rate_value', 'base_amount']);
        });
        Schema::table('budget_line_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('budget_deduction_id');
        });
        Schema::table('budget_deductions', function (Blueprint $table) {
            $table->dropUnique('budget_deductions_owner_slug');
            $table->dropIndex(['territory_type', 'territory_id']);
            $table->dropConstrainedForeignId('budget_line_id');
            $table->dropColumn(['territory_type', 'territory_id', 'applies_to_level', 'basis', 'basis_line_ids']);
            $table->unique('slug');
        });
    }
};
