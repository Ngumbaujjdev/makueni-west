<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Budgets become "a month or a whole year" with no approval step
 * (docs/specs/budgets-spec.md):
 * - period_month (null = whole year) replaces budget type + period + dates;
 * - status is draft | active | closed ("In use" = active);
 * - who started/closed a budget, and when;
 * - budget_logs.action becomes a plain string so new kinds of history
 *   entry don't need a migration.
 *
 * Budgets that are neither a month nor a year (the seeded diocese quarterly
 * drafts) are soft-deleted with a "retired" history entry; down() brings
 * them back. Plain DB queries throughout so no model hooks fire.
 */
return new class extends Migration
{
    private const OLD_STATUSES = "'draft','submitted','under_review','approved','rejected','active','closed'";

    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->unsignedTinyInteger('period_month')->nullable()->after('fiscal_year');
            $table->timestamp('started_at')->nullable()->after('status_id');
            $table->foreignId('started_by')->nullable()->after('started_at')->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('started_by');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $table->index(['territory_type', 'territory_id', 'fiscal_year', 'period_month'], 'budgets_place_period_index');
        });

        // The budget type is no longer part of a budget.
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropForeign(['budget_type_id']);
        });
        Schema::table('budgets', function (Blueprint $table) {
            $table->unsignedBigInteger('budget_type_id')->nullable()->change();
            $table->foreign('budget_type_id')->references('id')->on('budget_types')->nullOnDelete();
        });

        Schema::table('budget_logs', function (Blueprint $table) {
            $table->string('action', 40)->change();
        });

        // Statuses: no approval any more.
        DB::table('budgets')->whereIn('status', ['submitted', 'under_review', 'approved'])->update(['status' => 'active']);
        DB::table('budgets')->where('status', 'rejected')->update(['status' => 'draft']);
        DB::statement("ALTER TABLE budgets MODIFY status ENUM('draft','active','closed') NOT NULL DEFAULT 'draft'");

        // Month or year, from the dates.
        foreach (DB::table('budgets')->whereNull('deleted_at')->get() as $budget) {
            $start = \Carbon\CarbonImmutable::parse($budget->start_date);
            $end = \Carbon\CarbonImmutable::parse($budget->end_date);
            $isMonth = $start->day === 1 && $end->isSameDay($start->endOfMonth());
            $isYear = $start->dayOfYear === 1 && $end->isSameDay($start->endOfYear());

            if ($isMonth || $isYear) {
                DB::table('budgets')->where('id', $budget->id)->update([
                    'period_month' => $isMonth ? $start->month : null,
                    'fiscal_year' => $start->year,
                ]);

                continue;
            }

            DB::table('budgets')->where('id', $budget->id)->update(['deleted_at' => now()]);
            DB::table('budget_logs')->insert([
                'budget_id' => $budget->id,
                'action' => 'retired',
                'description' => "Retired: a budget covers a month or a whole year now, and this one ran {$start->format('j M Y')} – {$end->format('j M Y')}.",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Bring back what up() retired.
        $retired = DB::table('budget_logs')->where('action', 'retired')->pluck('budget_id');
        DB::table('budgets')->whereIn('id', $retired)->update(['deleted_at' => null]);
        DB::table('budget_logs')->where('action', 'retired')->delete();

        DB::statement('ALTER TABLE budgets MODIFY status ENUM('.self::OLD_STATUSES.") NOT NULL DEFAULT 'draft'");

        $known = ['created', 'updated', 'deleted', 'status_changed', 'deduction_applied', 'deduction_reversed', 'line_item_added', 'line_item_updated', 'line_item_deleted', 'submitted', 'approved', 'rejected', 'activated', 'closed'];
        DB::table('budget_logs')->whereNotIn('action', $known)->update(['action' => 'updated']);
        DB::statement("ALTER TABLE budget_logs MODIFY action ENUM('".implode("','", $known)."') NOT NULL");

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropIndex('budgets_place_period_index');
            $table->dropForeign(['started_by']);
            $table->dropForeign(['closed_by']);
            $table->dropColumn(['period_month', 'started_at', 'started_by', 'closed_at', 'closed_by']);
        });
        // budget_type_id stays nullable: budgets made since have none.
    }
};
