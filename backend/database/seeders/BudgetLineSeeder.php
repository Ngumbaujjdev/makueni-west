<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\BudgetCategory;
use App\Models\BudgetLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BudgetLineSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🚀 Seeding Budget Lines...');

        DB::beginTransaction();

        try {
            // Delete existing data
            $this->command->info('🗑️  Deleting existing budget lines...');
            BudgetLine::query()->delete();

            // Get categories
            $incomeCategory = BudgetCategory::where('slug', 'income')->first();
            $expenseCategory = BudgetCategory::where('slug', 'expense')->first();

            if (!$incomeCategory || !$expenseCategory) {
                throw new \Exception('Budget categories not found. Please run BudgetCategorySeeder first.');
            }

            // Income Lines
            $incomeLines = [
                ['name' => 'Tithes', 'territory_scope' => 'all', 'description' => 'Member tithes and offerings', 'display_order' => 1],
                ['name' => 'Offerings', 'territory_scope' => 'all', 'description' => 'General offerings', 'display_order' => 2],
                ['name' => 'Donations', 'territory_scope' => 'all', 'description' => 'Donations from individuals or organizations', 'display_order' => 3],
                ['name' => 'Fundraising Events', 'territory_scope' => 'all', 'description' => 'Income from fundraising activities', 'display_order' => 4],
                ['name' => 'Diocesan Allocations', 'territory_scope' => 'church', 'description' => 'Funds received from diocese', 'display_order' => 5],
                ['name' => 'Regional Allocations', 'territory_scope' => 'church', 'description' => 'Funds received from region', 'display_order' => 6],
                ['name' => 'Investment Income', 'territory_scope' => 'all', 'description' => 'Returns from investments', 'display_order' => 7],
                ['name' => 'Rental Income', 'territory_scope' => 'all', 'description' => 'Income from property rentals', 'display_order' => 8],
                ['name' => 'Special Collections', 'territory_scope' => 'all', 'description' => 'Special purpose collections', 'display_order' => 9],
                ['name' => 'Church Contributions', 'territory_scope' => 'diocese', 'description' => 'Contributions from churches', 'display_order' => 10],
                ['name' => 'Grants', 'territory_scope' => 'all', 'description' => 'Grants from external organizations', 'display_order' => 11],
                ['name' => 'Other Income', 'territory_scope' => 'all', 'description' => 'Miscellaneous income', 'display_order' => 99],
            ];

            foreach ($incomeLines as $line) {
                BudgetLine::create([
                    'budget_category_id' => $incomeCategory->id,
                    'name' => $line['name'],
                    'slug' => Str::slug($line['name']),
                    'territory_scope' => $line['territory_scope'],
                    'description' => $line['description'],
                    'is_system_default' => true,
                    'is_active' => true,
                    'display_order' => $line['display_order'],
                ]);
            }

            $this->command->info('✅ Income lines created (' . count($incomeLines) . ' lines)');

            // Expense Lines
            $expenseLines = [
                ['name' => 'Salaries & Wages', 'territory_scope' => 'all', 'description' => 'Staff salaries and wages', 'display_order' => 1],
                ['name' => 'Pastoral Support', 'territory_scope' => 'church', 'description' => 'Pastor salary and support', 'display_order' => 2],
                ['name' => 'Staff Housing', 'territory_scope' => 'all', 'description' => 'Housing allowances for staff', 'display_order' => 3],
                ['name' => 'Equipment', 'territory_scope' => 'all', 'description' => 'Purchase and maintenance of equipment', 'display_order' => 4],
                ['name' => 'Retreats', 'territory_scope' => 'all', 'description' => 'Retreat expenses', 'display_order' => 5],
                ['name' => 'Utilities - Electricity', 'territory_scope' => 'all', 'description' => 'Electricity bills', 'display_order' => 6],
                ['name' => 'Utilities - Water', 'territory_scope' => 'all', 'description' => 'Water bills', 'display_order' => 7],
                ['name' => 'Utilities - Internet', 'territory_scope' => 'all', 'description' => 'Internet and communication services', 'display_order' => 8],
                ['name' => 'Building Maintenance', 'territory_scope' => 'all', 'description' => 'Building repairs and maintenance', 'display_order' => 9],
                ['name' => 'Ministry Programs', 'territory_scope' => 'all', 'description' => 'Various ministry program expenses', 'display_order' => 10],
                ['name' => 'Youth Ministry', 'territory_scope' => 'all', 'description' => 'Youth ministry activities', 'display_order' => 11],
                ['name' => 'Women Ministry', 'territory_scope' => 'all', 'description' => 'Women ministry activities', 'display_order' => 12],
                ['name' => 'Men Ministry', 'territory_scope' => 'all', 'description' => 'Men ministry activities', 'display_order' => 13],
                ['name' => 'Children Ministry', 'territory_scope' => 'all', 'description' => 'Children ministry activities', 'display_order' => 14],
                ['name' => 'Travel & Transportation', 'territory_scope' => 'all', 'description' => 'Travel and transportation costs', 'display_order' => 15],
                ['name' => 'Communications', 'territory_scope' => 'all', 'description' => 'Communication expenses', 'display_order' => 16],
                ['name' => 'Office Supplies', 'territory_scope' => 'all', 'description' => 'Office supplies and stationery', 'display_order' => 17],
                ['name' => 'Insurance', 'territory_scope' => 'all', 'description' => 'Insurance premiums', 'display_order' => 18],
                ['name' => 'Charitable Activities', 'territory_scope' => 'all', 'description' => 'Charitable giving and community support', 'display_order' => 19],
                ['name' => 'Events & Conferences', 'territory_scope' => 'all', 'description' => 'Special events and conferences', 'display_order' => 20],
                ['name' => 'Training & Development', 'territory_scope' => 'all', 'description' => 'Staff training and development', 'display_order' => 21],
                ['name' => 'Music & Worship', 'territory_scope' => 'all', 'description' => 'Music equipment and worship materials', 'display_order' => 22],
                ['name' => 'Security Services', 'territory_scope' => 'all', 'description' => 'Security services and systems', 'display_order' => 23],
                ['name' => 'Cleaning Services', 'territory_scope' => 'all', 'description' => 'Cleaning and janitorial services', 'display_order' => 24],
                ['name' => 'Legal & Professional Fees', 'territory_scope' => 'all', 'description' => 'Legal and professional service fees', 'display_order' => 25],
                ['name' => 'Bank Charges', 'territory_scope' => 'all', 'description' => 'Bank fees and charges', 'display_order' => 26],
                ['name' => 'Diocesan Tithe', 'territory_scope' => 'church', 'description' => 'Tithe remittance to diocese', 'display_order' => 27],
                ['name' => 'Regional Levy', 'territory_scope' => 'church', 'description' => 'Contribution to regional operations', 'display_order' => 28],
                ['name' => 'Emergency Fund', 'territory_scope' => 'all', 'description' => 'Emergency fund contributions', 'display_order' => 29],
                ['name' => 'Other Expenses', 'territory_scope' => 'all', 'description' => 'Miscellaneous expenses', 'display_order' => 99],
            ];

            foreach ($expenseLines as $line) {
                BudgetLine::create([
                    'budget_category_id' => $expenseCategory->id,
                    'name' => $line['name'],
                    'slug' => Str::slug($line['name']),
                    'territory_scope' => $line['territory_scope'],
                    'description' => $line['description'],
                    'is_system_default' => true,
                    'is_active' => true,
                    'display_order' => $line['display_order'],
                ]);
            }

            $this->command->info('✅ Expense lines created (' . count($expenseLines) . ' lines)');

            DB::commit();
            $this->command->info('🎉 Budget lines seeded successfully! Total: ' . (count($incomeLines) + count($expenseLines)) . ' lines');

        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('❌ Seeding failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
