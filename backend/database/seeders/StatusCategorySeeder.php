<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\StatusCategory;
use Illuminate\Support\Facades\DB;

class StatusCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🚀 Seeding Status Categories...');

        DB::beginTransaction();

        try {
            // Delete existing data
            $this->command->info('🗑️  Deleting existing status categories...');
            StatusCategory::query()->delete();

            $categories = [
                [
                    'name' => 'Budget',
                    'slug' => 'budget',
                    'description' => 'Status categories for budget management',
                    'is_active' => true,
                    'display_order' => 1,
                ],
                [
                    'name' => 'Donation',
                    'slug' => 'donation',
                    'description' => 'Status categories for donation tracking',
                    'is_active' => true,
                    'display_order' => 2,
                ],
                [
                    'name' => 'Payment',
                    'slug' => 'payment',
                    'description' => 'Status categories for payment processing',
                    'is_active' => true,
                    'display_order' => 3,
                ],
                [
                    'name' => 'Project',
                    'slug' => 'project',
                    'description' => 'Status categories for project management',
                    'is_active' => true,
                    'display_order' => 4,
                ],
            ];

            foreach ($categories as $category) {
                StatusCategory::create($category);
            }

            $this->command->info('✅ Status categories created (' . count($categories) . ' categories)');

            DB::commit();
            $this->command->info('🎉 Status categories seeded successfully!');

        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('❌ Seeding failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
