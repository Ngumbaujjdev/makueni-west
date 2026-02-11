<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Territory;
use App\Models\UserTerritoryAssignment;
use App\Enums\TerritoryType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RegionsSeeder extends Seeder
{
    public function run(): void
    {
        // Get the diocese
        $diocese = Territory::where('code', 'CCI-MWD')->first();

        if (!$diocese) {
            $this->command->error('❌ Diocese not found. Please run DioceseSeeder first.');
            return;
        }

        $this->command->info("📍 Found Diocese: {$diocese->name} (ID: {$diocese->id})");
        $this->command->info('');

        // === STEP 1: AGGRESSIVE DELETE OF ALL REGIONS ===
        $this->command->info('🗑️  DELETING ALL EXISTING REGIONS...');
        $this->command->info(str_repeat('=', 70));

        // Method 1: Find all regions (any parent)
        $allRegions = Territory::where('territory_type', TerritoryType::REGION)->get();

        if ($allRegions->count() > 0) {
            $this->command->warn("Found {$allRegions->count()} existing regions:");
            foreach ($allRegions as $region) {
                $this->command->line("  ❌ {$region->name} (Code: {$region->code}, ID: {$region->id}, Parent: {$region->parent_territory_id})");
            }

            // Delete user territory assignments for ALL regions
            $regionIds = $allRegions->pluck('id')->toArray();
            if (!empty($regionIds)) {
                $deletedAssignments = UserTerritoryAssignment::whereIn('territory_id', $regionIds)->delete();
                $this->command->info("   Deleted {$deletedAssignments} user assignments");
            }

            // Delete ALL regions (regardless of parent)
            $deletedCount = Territory::where('territory_type', TerritoryType::REGION)->delete();
            $this->command->info("✅ Deleted {$deletedCount} regions");

            // Double-check: Force delete by specific codes if any remain
            DB::table('territories')->whereIn('code', [
                'CCI-MWD-SHR',
                'CCI-MWD-KLG',
                'CCI-MWD-KLB',
                'CCI-MWD-KJS',
                'CCI-MWD-MML',
                'CCI-MWD-NZL' // Old Nzaui
            ])->delete();

            $this->command->info("✅ Force-deleted any remaining regions by code");

        } else {
            $this->command->info('✅ No existing regions found');
        }

        // Verify deletion
        $remainingRegions = Territory::where('territory_type', TerritoryType::REGION)->count();
        if ($remainingRegions > 0) {
            $this->command->error("⚠️  WARNING: Still have {$remainingRegions} regions in database!");
            $this->command->error("Run this in tinker first:");
            $this->command->error("DB::table('territories')->where('territory_type', 'region')->delete();");
            return;
        }

        $this->command->info('');
        $this->command->info('🌱 CREATING NEW REGIONS...');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('');

        // === STEP 2: CREATE 5 CORRECT REGIONS ===
        $regions = [
            [
                'name' => 'Sultan Hamud Region',
                'code' => 'SHR',
                'overseer_name' => 'Rev. Titus Kenzi',
                'overseer_phone' => '0797806292',
                'overseer_spouse' => 'Esther Mutiso',
                'spouse_phone' => '0729159342',
                'town' => 'Sultan Hamud',
                'county' => 'Makueni',
                'churches_count' => 24,
                'description' => 'Sultan Hamud Region covering Sultan Hamud area with 24 churches',
            ],
            [
                'name' => 'Kilungu Region',
                'code' => 'KLG',
                'overseer_name' => 'Rev. Philip Mutiso',
                'overseer_phone' => '0734892294',
                'overseer_spouse' => 'Peninah Philip',
                'spouse_phone' => '0736648842',
                'town' => 'Kilungu',
                'county' => 'Makueni',
                'churches_count' => 12,
                'description' => 'Kilungu Region covering Kilungu area with 12 churches',
            ],
            [
                'name' => 'Kalamba Region',
                'code' => 'KLB',
                'overseer_name' => 'Rev. Christopher Owuor Misori',
                'overseer_phone' => '0723711634',
                'overseer_spouse' => 'Pst. Beatrice Owuor',
                'spouse_phone' => '0713025322',
                'town' => 'Kalamba',
                'county' => 'Makueni',
                'churches_count' => 15,
                'description' => 'Kalamba Region covering Kalamba area with 13 churches and 2 fellowships',
            ],
            [
                'name' => 'Kajiado South Region',
                'code' => 'KJS',
                'overseer_name' => 'Prof. Rev. Moses Makonjio Okello',
                'overseer_phone' => '0718800398',
                'overseer_spouse' => 'Ms. Roselidah Okello',
                'spouse_phone' => '0728726946',
                'town' => 'Emali',
                'county' => 'Kajiado',
                'churches_count' => 8,
                'description' => 'Kajiado South Region covering Kajiado South area with 8 churches',
            ],
            [
                'name' => 'Mumela Region',
                'code' => 'MML',
                'overseer_name' => 'Rev. Dishon Maweu',
                'overseer_phone' => '0725973875',
                'overseer_spouse' => 'Lilian Dishon',
                'spouse_phone' => '0727297257',
                'town' => 'Mumela',
                'county' => 'Makueni',
                'churches_count' => 11,
                'description' => 'Mumela Region covering Mumela area with 11 churches',
            ],
        ];

        $createdCount = 0;
        foreach ($regions as $index => $region) {
            try {
                $territory = Territory::create([
                    'name' => $region['name'],
                    'code' => "CCI-MWD-{$region['code']}",
                    'territory_type' => TerritoryType::REGION,
                    'parent_territory_id' => $diocese->id,
                    'level' => 2,
                    'is_active' => true,
                    'address' => 'P.O. Box ' . (100 + $index) . ', ' . $region['town'],
                    'phone' => '+254' . substr($region['overseer_phone'], 1),
                    'email' => strtolower(str_replace(' ', '', explode(' ', $region['name'])[0])) . '@makueniwestdiocese.or.ke',
                    'town' => $region['town'],
                    'county' => $region['county'],
                    'latitude' => -1.8 - ($index * 0.1),
                    'longitude' => 37.6 + ($index * 0.1),
                    'established_date' => Carbon::parse('2010-03-15'),
                    'description' => $region['description'],
                    'metadata' => [
                        'overseer_name' => $region['overseer_name'],
                        'overseer_phone' => $region['overseer_phone'],
                        'overseer_spouse' => $region['overseer_spouse'],
                        'spouse_phone' => $region['spouse_phone'],
                        'churches_count' => $region['churches_count'],
                    ],
                ]);

                $createdCount++;
                $this->command->info("✅ [{$createdCount}/5] Created: {$region['name']}");
                $this->command->line("   Code: {$territory->code}");
                $this->command->line("   ID: {$territory->id}");
                $this->command->line("   Overseer: {$region['overseer_name']}");
                $this->command->line("   Churches: {$region['churches_count']}");
                $this->command->info('');

            } catch (\Exception $e) {
                $this->command->error("❌ Failed to create {$region['name']}: " . $e->getMessage());
                $this->command->error("   This region code already exists. Run the manual cleanup first.");
            }
        }

        // === FINAL SUMMARY ===
        $this->command->info('');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('✅ REGIONS SEEDING COMPLETED!');
        $this->command->info(str_repeat('=', 70));

        // Verify final count
        $finalCount = Territory::where('parent_territory_id', $diocese->id)
            ->where('territory_type', TerritoryType::REGION)
            ->count();

        $this->command->info("📊 Total regions in database: {$finalCount}");

        if ($finalCount === 5) {
            $this->command->info('✅ SUCCESS: All 5 regions created correctly!');
            $this->command->info('');
            $this->command->info('🎯 Next step: Run ChurchesSeeder to create 70 churches');
        } else {
            $this->command->warn("⚠️  WARNING: Expected 5 regions but found {$finalCount}");
            if ($finalCount < 5) {
                $this->command->error("\n❌ MANUAL CLEANUP REQUIRED!");
                $this->command->error("Run this in tinker:");
                $this->command->error("DB::table('territories')->where('territory_type', 'region')->delete();");
                $this->command->error("Then run this seeder again.");
            }
        }
    }
}
