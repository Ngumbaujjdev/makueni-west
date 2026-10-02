<?php

namespace Database\Seeders;

use App\Models\BudgetDeduction;
use App\Models\BudgetLine;
use App\Services\Budgets\BudgetBook;
use App\Services\Budgets\Deductions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The standard deductions every church starts with (docs/specs/budgets-spec.md
 * → Deductions): "Diocese share: 10% of Tithes received", paid through the
 * standard "Diocesan Tithe" money-out line. Shown to churches as "Standard",
 * and reaching every Draft / In use budget at once.
 *
 * Only created when missing - once it exists, Budget Settings is where it's
 * changed or switched off, and re-running this never undoes that.
 */
class StandardDeductionsSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('💰 STANDARD DEDUCTIONS');

        $diocese = DB::table('territories')->where('territory_type', 'diocese')->whereNull('deleted_at')->orderBy('id')->first();
        $tithes = BudgetLine::whereNull('territory_id')->where('name', 'Tithes')->first();
        $paidThrough = BudgetLine::whereNull('territory_id')->where('name', 'Diocesan Tithe')->first();
        if (! $diocese || ! $tithes || ! $paidThrough) {
            $this->command->error('   ❌ Diocese, the Tithes line or the Diocesan Tithe line not found');

            return;
        }

        $deduction = BudgetDeduction::withTrashed()->where('territory_type', 'diocese')->where('territory_id', $diocese->id)->where('slug', 'diocese-share')->first();
        if ($deduction) {
            $this->command->info("   ✓ {$deduction->name} already set up - left as it is");

            return;
        }

        $deduction = BudgetDeduction::create([
            'name' => 'Diocese share',
            'slug' => 'diocese-share',
            'description' => 'The standard share of tithes received, sent through Diocesan Tithe',
            'deduction_type' => 'percentage',
            'deduction_value' => 10,
            'applies_to' => 'income',
            'territory_type' => 'diocese',
            'territory_id' => $diocese->id,
            'territory_scope' => 'church',
            'applies_to_level' => 'church',
            'budget_line_id' => $paidThrough->id,
            'basis' => 'lines',
            'basis_line_ids' => [$tithes->id],
            'is_active' => true,
            'display_order' => 1,
        ]);

        $budgets = app(Deductions::class)->openBudgetsFor($deduction);
        $book = app(BudgetBook::class);
        foreach ($budgets as $budget) {
            $book->reapplyDeductions($budget);
        }
        $this->command->info("   ✅ Diocese share: 10% of Tithes received, every church - on {$budgets->count()} open budgets");
    }
}
