<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Status;
use App\Models\StatusCategory;
use Illuminate\Support\Facades\DB;

class StatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🚀 Seeding Statuses...');

        DB::beginTransaction();

        try {
            // Delete existing data
            $this->command->info('🗑️  Deleting existing statuses...');
            Status::query()->delete();

            // Get budget category
            $budgetCategory = StatusCategory::where('slug', 'budget')->first();
            if (!$budgetCategory) {
                throw new \Exception('Budget status category not found. Please run StatusCategorySeeder first.');
            }

            // Budget Statuses
            $budgetStatuses = [
                [
                    'status_category_id' => $budgetCategory->id,
                    'name' => 'Draft',
                    'slug' => 'draft',
                    'description' => 'Budget is in draft mode and can be edited',
                    'color' => '#3B82F6', // Blue
                    'icon' => 'ri-draft-line',
                    'display_order' => 1,
                    'is_initial' => true,
                    'is_final' => false,
                    'is_active' => true,
                ],
                [
                    'status_category_id' => $budgetCategory->id,
                    'name' => 'Submitted',
                    'slug' => 'submitted',
                    'description' => 'Budget has been submitted for review',
                    'color' => '#F59E0B', // Amber
                    'icon' => 'ri-send-plane-line',
                    'display_order' => 2,
                    'is_initial' => false,
                    'is_final' => false,
                    'is_active' => true,
                ],
                [
                    'status_category_id' => $budgetCategory->id,
                    'name' => 'Under Review',
                    'slug' => 'under_review',
                    'description' => 'Budget is being reviewed by approvers',
                    'color' => '#F97316', // Orange
                    'icon' => 'ri-eye-line',
                    'display_order' => 3,
                    'is_initial' => false,
                    'is_final' => false,
                    'is_active' => true,
                ],
                [
                    'status_category_id' => $budgetCategory->id,
                    'name' => 'Approved',
                    'slug' => 'approved',
                    'description' => 'Budget has been approved',
                    'color' => '#10B981', // Green
                    'icon' => 'ri-checkbox-circle-line',
                    'display_order' => 4,
                    'is_initial' => false,
                    'is_final' => false,
                    'is_active' => true,
                ],
                [
                    'status_category_id' => $budgetCategory->id,
                    'name' => 'Rejected',
                    'slug' => 'rejected',
                    'description' => 'Budget has been rejected',
                    'color' => '#EF4444', // Red
                    'icon' => 'ri-close-circle-line',
                    'display_order' => 5,
                    'is_initial' => false,
                    'is_final' => false,
                    'is_active' => true,
                ],
                [
                    'status_category_id' => $budgetCategory->id,
                    'name' => 'Active',
                    'slug' => 'active',
                    'description' => 'Budget is currently active and in use',
                    'color' => '#8B5CF6', // Purple
                    'icon' => 'ri-play-circle-line',
                    'display_order' => 6,
                    'is_initial' => false,
                    'is_final' => false,
                    'is_active' => true,
                ],
                [
                    'status_category_id' => $budgetCategory->id,
                    'name' => 'Closed',
                    'slug' => 'closed',
                    'description' => 'Budget has been closed and locked',
                    'color' => '#6B7280', // Gray
                    'icon' => 'ri-lock-line',
                    'display_order' => 7,
                    'is_initial' => false,
                    'is_final' => true,
                    'is_active' => true,
                ],
            ];

            foreach ($budgetStatuses as $status) {
                Status::create($status);
            }

            $this->command->info('✅ Budget statuses created (' . count($budgetStatuses) . ' statuses)');

            DB::commit();
            $this->command->info('🎉 Statuses seeded successfully!');

        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('❌ Seeding failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
