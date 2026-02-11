<?php

namespace Database\Seeders;

use App\Models\BudgetDeduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BudgetDeductionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $deductions = [
            [
                'name' => 'Tax Withholding',
                'slug' => 'tax-withholding',
                'description' => 'Mandatory tax deduction on income',
                'deduction_type' => 'percentage',
                'deduction_value' => 10.00,
                'applies_to' => 'income',
                'territory_scope' => 'all',
                'is_mandatory' => true,
                'is_active' => true,
                'display_order' => 1,
            ],
            [
                'name' => 'Diocesan Levy',
                'slug' => 'diocesan-levy',
                'description' => 'Contribution to diocesan operations',
                'deduction_type' => 'percentage',
                'deduction_value' => 5.00,
                'applies_to' => 'income',
                'territory_scope' => 'diocese',
                'is_mandatory' => true,
                'is_active' => true,
                'display_order' => 2,
            ],
            [
                'name' => 'Administrative Fee',
                'slug' => 'administrative-fee',
                'description' => 'Fixed administrative processing fee',
                'deduction_type' => 'fixed_amount',
                'deduction_value' => 5000.00,
                'applies_to' => 'both',
                'territory_scope' => 'all',
                'is_mandatory' => false,
                'is_active' => true,
                'display_order' => 3,
            ],
            [
                'name' => 'Regional Support Fund',
                'slug' => 'regional-support-fund',
                'description' => 'Support fund for regional activities',
                'deduction_type' => 'percentage',
                'deduction_value' => 3.00,
                'applies_to' => 'income',
                'territory_scope' => 'region',
                'is_mandatory' => false,
                'is_active' => true,
                'display_order' => 4,
            ],
        ];

        foreach ($deductions as $deduction) {
            BudgetDeduction::create($deduction);
        }

        $this->command->info('Budget deductions seeded successfully!');
    }
}
