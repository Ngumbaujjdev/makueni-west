<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\BudgetType;
use Illuminate\Support\Facades\DB;

class BudgetTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🚀 Seeding Budget Types...');

        DB::beginTransaction();

        try {
            // Use firstOrCreate to avoid FK constraint issues
            $budgetTypes = [
                [
                    'name' => 'Monthly',
                    'slug' => 'monthly',
                    'duration_months' => 1,
                    'is_active' => true,
                ],
                [
                    'name' => 'Quarterly',
                    'slug' => 'quarterly',
                    'duration_months' => 3,
                    'is_active' => true,
                ],
                [
                    'name' => 'Semi-Annual',
                    'slug' => 'semi-annual',
                    'duration_months' => 6,
                    'is_active' => true,
                ],
                [
                    'name' => 'Yearly',
                    'slug' => 'yearly',
                    'duration_months' => 12,
                    'is_active' => true,
                ],
            ];

            foreach ($budgetTypes as $type) {
                BudgetType::updateOrCreate(
                    ['slug' => $type['slug']],
                    $type
                );
            }

            // Deactivate bi-annual and custom if they exist
            BudgetType::whereIn('slug', ['bi-annual', 'custom'])->update(['is_active' => false]);

            $this->command->info('✅ Budget types created/updated (4 active types)');

            DB::commit();
            $this->command->info('🎉 Budget types seeded successfully!');

        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('❌ Seeding failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
