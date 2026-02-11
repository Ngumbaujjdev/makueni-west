<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\BudgetCategory;
use Illuminate\Support\Facades\DB;

class BudgetCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🚀 Seeding Budget Categories...');

        DB::beginTransaction();

        try {
            // Delete existing data
            $this->command->info('🗑️  Deleting existing budget categories...');
            BudgetCategory::query()->delete();

            $categories = [
                [
                    'name' => 'Income',
                    'slug' => 'income',
                    'description' => 'All income and revenue sources',
                    'is_active' => true,
                ],
                [
                    'name' => 'Expense',
                    'slug' => 'expense',
                    'description' => 'All expenses and expenditures',
                    'is_active' => true,
                ],
            ];

            foreach ($categories as $category) {
                BudgetCategory::create($category);
            }

            $this->command->info('✅ Budget categories created (2 categories)');

            DB::commit();
            $this->command->info('🎉 Budget categories seeded successfully!');

        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('❌ Seeding failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
