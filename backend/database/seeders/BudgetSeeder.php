<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Budget;
use App\Models\BudgetLineItem;
use App\Models\BudgetType;
use App\Models\BudgetCategory;
use App\Models\BudgetLine;
use App\Models\BudgetPeriod;
use App\Models\FiscalYear;
use App\Models\Diocese;
use App\Models\Church;
use App\Models\User;
use App\Models\Status;
use App\Models\StatusCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class BudgetSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🚀 Seeding Budgets with budget_period_id...');

        // DISABLE FK CHECKS COMPLETELY
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        try {
            // AGGRESSIVELY DELETE EVERYTHING
            $this->command->info('🗑️  Deleting ALL budget-related data...');
            DB::table('budget_deduction_items')->truncate();
            DB::table('budget_deductions')->truncate();
            DB::table('budget_logs')->truncate();
            DB::table('budget_line_items')->truncate();
            DB::table('budgets')->truncate();
            $this->command->info('  ✓ All budget data deleted');

            // Get required data
            $diocese = Diocese::first();
            $churches = Church::take(3)->get();
            $yearlyType = BudgetType::where('slug', 'yearly')->first();
            $quarterlyType = BudgetType::where('slug', 'quarterly')->first();
            $monthlyType = BudgetType::where('slug', 'monthly')->first();
            $incomeCategory = BudgetCategory::where('slug', 'income')->first();
            $expenseCategory = BudgetCategory::where('slug', 'expense')->first();
            $incomeLines = BudgetLine::where('budget_category_id', $incomeCategory->id)->get();
            $expenseLines = BudgetLine::where('budget_category_id', $expenseCategory->id)->get();
            $user = User::first();
            
            // Get statuses
            $draftStatus = $this->getStatusIdBySlug('draft');
            $activeStatus = $this->getStatusIdBySlug('active');
            $approvedStatus = $this->getStatusIdBySlug('approved');

            $count = 0;

            // Seed budgets for years 2024, 2025, 2026
            foreach ([2024, 2025, 2026] as $year) {
                $fiscalYear = FiscalYear::where('year', $year)->first();
                if (!$fiscalYear) continue;

                // Get status based on year
                $status = $year < 2026 ? $activeStatus : $draftStatus;

                // ========================================
                // YEARLY DIOCESE BUDGETS
                // ========================================
                $yearlyPeriod = BudgetPeriod::where('budget_type_id', $yearlyType->id)
                    ->where('fiscal_year_id', $fiscalYear->id)
                    ->first();

                if ($yearlyPeriod && $diocese) {
                    $budget = $this->createBudget(
                        $diocese,
                        'diocese',
                        $yearlyType,
                        $yearlyPeriod,
                        "Diocese Annual Budget {$year}",
                        $status,
                        $user->id
                    );
                    $this->createLineItems($budget, $incomeLines, $expenseLines, 'diocese', $user->id);
                    $count++;
                    $this->command->info("  ✓ Created Diocese Yearly Budget {$year}");
                }

                // ========================================
                // QUARTERLY DIOCESE BUDGETS  
                // ========================================
                $quarterlyPeriods = BudgetPeriod::where('budget_type_id', $quarterlyType->id)
                    ->where('fiscal_year_id', $fiscalYear->id)
                    ->get();

                foreach ($quarterlyPeriods as $qPeriod) {
                    if ($diocese) {
                        $budget = $this->createBudget(
                            $diocese,
                            'diocese',
                            $quarterlyType,
                            $qPeriod,
                            "Diocese {$qPeriod->name} Budget",
                            $status,
                            $user->id
                        );
                        $this->createLineItems($budget, $incomeLines, $expenseLines, 'diocese', $user->id);
                        $count++;
                    }
                }
                $this->command->info("  ✓ Created Diocese Quarterly Budgets {$year}");

                // ========================================
                // CHURCH BUDGETS (Monthly for 2026 only)
                // ========================================
                if ($year == 2026) {
                    foreach ($churches as $church) {
                        // Create monthly budgets for Q1 2026 (Jan, Feb, Mar)
                        $monthlyPeriods = BudgetPeriod::where('budget_type_id', $monthlyType->id)
                            ->where('fiscal_year_id', $fiscalYear->id)
                            ->whereHas('fiscalMonth', fn($q) => $q->whereIn('number', [1, 2, 3]))
                            ->get();

                        foreach ($monthlyPeriods as $mPeriod) {
                            $budget = $this->createBudget(
                                $church,
                                'church',
                                $monthlyType,
                                $mPeriod,
                                "{$church->name} {$mPeriod->name} Budget",
                                $draftStatus,
                                $user->id
                            );
                            $this->createLineItems($budget, $incomeLines, $expenseLines, 'church', $user->id);
                            $count++;
                        }
                        $this->command->info("  ✓ Created Church Monthly Budgets for {$church->name}");
                    }
                }
            }

            // RE-ENABLE FK CHECKS
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            $this->command->info("🎉 Seeded {$count} budgets successfully!");

        } catch (\Exception $e) {
            // RE-ENABLE FK CHECKS even on error
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            $this->command->error('❌ Seeding failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create a budget
     */
    private function createBudget(
        $territory,
        string $territoryType,
        $budgetType,
        BudgetPeriod $period,
        string $name,
        int $status,
        int $createdBy
    ): Budget {
        return Budget::create([
            'name' => $name,
            'slug' => Str::slug($name),
            'budget_type_id' => $budgetType->id,
            'budget_period_id' => $period->id,
            'territory_type' => $territoryType,
            'territory_id' => $territory->id,
            'fiscal_year' => $period->fiscalYear->year,
            'start_date' => $period->start_date,
            'end_date' => $period->end_date,
            'status_id' => $status,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Create budget line items
     */
    private function createLineItems(
        Budget $budget,
        $incomeLines,
        $expenseLines,
        string $territoryType,
        int $createdBy
    ): void {
        // Multiplier based on territory
        $multiplier = match($territoryType) {
            'diocese' => 10,
            'region' => 5,
            'church' => 1,
            default => 1,
        };

        // Income lines (sample based on the image provided by user)
        $incomeAmounts = [
            'tithes' => 1300000 * $multiplier / 10,
            'offerings' => 840000 * $multiplier / 10,
            'donations' => 228000 * $multiplier / 10,
            'harambee' => 120000 * $multiplier / 10,
            'special-offerings' => 350000 * $multiplier / 10,
        ];

        // Expense lines (based on user image)
        $expenseAmounts = [
            'salaries' => 480000 * $multiplier / 10,
            'utilities' => 30000 * $multiplier / 10,
            'maintenance' => 24000 * $multiplier / 10,
            'office-expenses' => 70000 * $multiplier / 10,
            'missions' => 50000 * $multiplier / 10,
            'events' => 300000 * $multiplier / 10,
            'bishop-allowance' => 120000 * $multiplier / 10,
            'meetings' => 135000 * $multiplier / 10,
        ];

        $incomeCategory = BudgetCategory::where('slug', 'income')->first();
        $expenseCategory = BudgetCategory::where('slug', 'expense')->first();

        foreach ($incomeLines->take(5) as $line) {
            $amount = $incomeAmounts[$line->slug] ?? rand(50000, 200000) * $multiplier / 10;
            BudgetLineItem::create([
                'budget_id' => $budget->id,
                'budget_line_id' => $line->id,
                'budget_category_id' => $incomeCategory->id,
                'budgeted_amount' => $amount,
                'actual_amount' => 0,
                'created_by' => $createdBy,
            ]);
        }

        foreach ($expenseLines->take(8) as $line) {
            $amount = $expenseAmounts[$line->slug] ?? rand(20000, 100000) * $multiplier / 10;
            BudgetLineItem::create([
                'budget_id' => $budget->id,
                'budget_line_id' => $line->id,
                'budget_category_id' => $expenseCategory->id,
                'budgeted_amount' => $amount,
                'actual_amount' => 0,
                'created_by' => $createdBy,
            ]);
        }
    }

    /**
     * Get status ID by slug
     */
    private function getStatusIdBySlug(string $slug): int
    {
        $budgetCategory = StatusCategory::where('slug', 'budget')->first();
        if (!$budgetCategory) {
            return 1;
        }
        
        $status = Status::where('slug', $slug)
            ->where('status_category_id', $budgetCategory->id)
            ->first();
            
        return $status?->id ?? 1;
    }
}
