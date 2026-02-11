<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\BudgetPeriod;
use App\Models\BudgetType;
use App\Models\FiscalYear;
use App\Models\FiscalMonth;
use App\Models\FiscalQuarter;
use App\Models\FiscalSemiAnnual;

class BudgetPeriodSeeder extends Seeder
{
    /**
     * Seed budget periods for all types and years.
     */
    public function run(): void
    {
        // Get budget types by slug
        $monthlyType = BudgetType::where('slug', 'monthly')->first();
        $quarterlyType = BudgetType::where('slug', 'quarterly')->first();
        $semiAnnualType = BudgetType::where('slug', 'semi-annual')->first();
        $yearlyType = BudgetType::where('slug', 'annual')->orWhere('slug', 'yearly')->first();

        if (!$monthlyType || !$quarterlyType || !$yearlyType) {
            $this->command->error('❌ Missing budget types. Please seed budget types first.');
            return;
        }

        $fiscalYears = FiscalYear::all();
        $months = FiscalMonth::all();

        $count = 0;

        foreach ($fiscalYears as $fiscalYear) {
            $year = $fiscalYear->year;

            // Monthly periods (12 per year)
            foreach ($months as $month) {
                $endOfMonth = \Carbon\Carbon::create($year, $month->number, 1)->endOfMonth();
                
                BudgetPeriod::firstOrCreate(
                    [
                        'budget_type_id' => $monthlyType->id,
                        'fiscal_year_id' => $fiscalYear->id,
                        'fiscal_month_id' => $month->id,
                    ],
                    [
                        'name' => "{$month->name} {$year}",
                        'start_date' => sprintf('%d-%02d-01', $year, $month->number),
                        'end_date' => $endOfMonth->format('Y-m-d'),
                        'is_active' => true,
                    ]
                );
                $count++;
            }

            // Quarterly periods (4 per year)
            $quarters = FiscalQuarter::where('fiscal_year_id', $fiscalYear->id)->get();
            foreach ($quarters as $quarter) {
                BudgetPeriod::firstOrCreate(
                    [
                        'budget_type_id' => $quarterlyType->id,
                        'fiscal_year_id' => $fiscalYear->id,
                        'fiscal_quarter_id' => $quarter->id,
                    ],
                    [
                        'name' => $quarter->name,
                        'start_date' => $quarter->start_date,
                        'end_date' => $quarter->end_date,
                        'is_active' => true,
                    ]
                );
                $count++;
            }

            // Semi-annual periods (2 per year)
            if ($semiAnnualType) {
                $semiAnnuals = FiscalSemiAnnual::where('fiscal_year_id', $fiscalYear->id)->get();
                foreach ($semiAnnuals as $semiAnnual) {
                    BudgetPeriod::firstOrCreate(
                        [
                            'budget_type_id' => $semiAnnualType->id,
                            'fiscal_year_id' => $fiscalYear->id,
                            'fiscal_semi_annual_id' => $semiAnnual->id,
                        ],
                        [
                            'name' => $semiAnnual->name,
                            'start_date' => $semiAnnual->start_date,
                            'end_date' => $semiAnnual->end_date,
                            'is_active' => true,
                        ]
                    );
                    $count++;
                }
            }

            // Yearly period (1 per year)
            BudgetPeriod::firstOrCreate(
                [
                    'budget_type_id' => $yearlyType->id,
                    'fiscal_year_id' => $fiscalYear->id,
                    'fiscal_month_id' => null,
                    'fiscal_quarter_id' => null,
                    'fiscal_semi_annual_id' => null,
                ],
                [
                    'name' => "Year {$year}",
                    'start_date' => "{$year}-01-01",
                    'end_date' => "{$year}-12-31",
                    'is_active' => true,
                ]
            );
            $count++;
        }

        $this->command->info("✅ Seeded {$count} budget periods");
    }
}
