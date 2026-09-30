<?php

namespace Database\Seeders;

use App\Models\BudgetLine;
use App\Models\Church;
use App\Models\Diocese;
use App\Models\Region;
use App\Models\User;
use App\Services\Budgets\BudgetBook;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Sample budgets for every level (docs/specs/budgets-spec.md): the
 * diocese's whole-year budgets for 2024-2026 (the past years in use), one
 * region's 2026 budget, and January-March 2026 month budgets for three
 * churches. Built through BudgetBook, the same way the pages save them.
 *
 * Wipes existing budgets first - sample data only.
 */
class BudgetSeeder extends Seeder
{
    /** Planned amounts for a church month; the diocese is 10x, a region 5x (per year: x12). */
    private const IN = ['tithes' => 130000, 'offerings' => 84000, 'donations' => 22800, 'harambee' => 12000, 'special-offerings' => 35000];

    private const OUT = ['salaries' => 48000, 'utilities' => 3000, 'maintenance' => 2400, 'office-expenses' => 7000, 'missions' => 5000, 'events' => 30000, 'bishop-allowance' => 12000, 'meetings' => 13500];

    public function run(BudgetBook $book): void
    {
        $this->command->info('🚀 Seeding sample budgets...');

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        foreach (['budget_deduction_items', 'budget_deductions', 'budget_logs', 'budget_line_items', 'budgets'] as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $user = User::first();
        $lines = BudgetLine::with('budgetCategory')->get()->keyBy('slug');
        $amounts = function (float $multiplier) use ($lines) {
            return collect([...self::IN, ...self::OUT])
                ->filter(fn ($amount, $slug) => $lines->has($slug))
                ->map(fn ($amount, $slug) => ['budget_line_id' => $lines[$slug]->id, 'amount' => $amount * $multiplier])
                ->values()->all();
        };
        $count = 0;

        if ($diocese = Diocese::first()) {
            foreach ([2024, 2025, 2026] as $year) {
                $book->save($user, 'diocese', $diocese->id, ['year' => $year, 'month' => null, 'lines' => $amounts(10 * 12), 'start' => $year < 2026]);
                $count++;
            }
        }

        if ($region = Region::first()) {
            $book->save($user, 'region', $region->id, ['year' => 2026, 'month' => null, 'lines' => $amounts(5 * 12)]);
            $count++;
        }

        foreach (Church::take(3)->get() as $church) {
            foreach ([1, 2, 3] as $month) {
                $book->save($user, 'church', $church->id, ['year' => 2026, 'month' => $month, 'lines' => $amounts(1)]);
                $count++;
            }
        }

        $this->command->info("🎉 Seeded {$count} budgets");
    }
}
