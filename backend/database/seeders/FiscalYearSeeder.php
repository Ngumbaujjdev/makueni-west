<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FiscalYear;

class FiscalYearSeeder extends Seeder
{
    /**
     * Seed fiscal years from 2024 to 2030.
     * Quarters and semi-annuals are auto-generated via FiscalYear::boot()
     */
    public function run(): void
    {
        $years = [2024, 2025, 2026, 2027, 2028, 2029, 2030];

        foreach ($years as $year) {
            FiscalYear::firstOrCreate(
                ['year' => $year],
                [
                    'year' => $year,
                    'start_date' => "{$year}-01-01",
                    'end_date' => "{$year}-12-31",
                    'is_active' => true,
                ]
            );
        }

        $this->command->info('✅ Seeded ' . count($years) . ' fiscal years (with auto-generated quarters & semi-annuals)');
    }
}
