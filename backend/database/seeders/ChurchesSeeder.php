<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Territory;
use App\Models\UserTerritoryAssignment;
use App\Enums\TerritoryType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ChurchesSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🏛️  CHURCHES SEEDER');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('');

        // === STEP 1: DELETE EXISTING CHURCHES ===
        $this->command->info('🗑️  Deleting existing churches...');

        $existingChurches = Territory::where('territory_type', TerritoryType::CHURCH)->get();

        if ($existingChurches->count() > 0) {
            $this->command->warn("Found {$existingChurches->count()} existing churches");

            // Delete user assignments
            $churchIds = $existingChurches->pluck('id')->toArray();
            if (!empty($churchIds)) {
                $deletedAssignments = UserTerritoryAssignment::whereIn('territory_id', $churchIds)->delete();
                $this->command->info("   Deleted {$deletedAssignments} user assignments");
            }

            // Delete churches
            $deletedCount = Territory::where('territory_type', TerritoryType::CHURCH)->delete();
            $this->command->info("✅ Deleted {$deletedCount} churches");
        } else {
            $this->command->info('✅ No existing churches found');
        }

        $this->command->info('');
        $this->command->info('🌱 CREATING CHURCHES...');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('');

        // === STEP 2: GET ALL REGIONS ===
        $regions = [
            'CCI-MWD-SHR' => 'Sultan Hamud Region',
            'CCI-MWD-KLB' => 'Kalamba Region',
            'CCI-MWD-MML' => 'Mumela Region',
            'CCI-MWD-KLG' => 'Kilungu Region',
            'CCI-MWD-KJS' => 'Kajiado South Region',
        ];

        $regionObjects = [];
        foreach ($regions as $code => $name) {
            $region = Territory::where('code', $code)->first();
            if (!$region) {
                $this->command->error("❌ Region {$name} ({$code}) not found!");
                return;
            }
            $regionObjects[$code] = $region;
            $this->command->info("✅ Found: {$name} (ID: {$region->id})");
        }

        $this->command->info('');

        // === STEP 3: CHURCHES DATA ===
        $churchesData = [
            'CCI-MWD-SHR' => [
                'CCI SULTAN HAMUD', 'CCI UPETE', 'CCI MALILI', 'CCI MUAMBANI',
                'CCI KASIKEU', 'CCI ILBISSIL', 'CCI MWANYANI', 'CCI MASAA',
                'CCI MUTYAMBUA', 'CCI MLOLONGO', 'CCI KATIVANI', 'CCI MBIINI',
                'CCI MBULUTINI', 'CCI ARROI', 'CCI MUAMBWANI', 'CCI MIKUINI',
                'CCI OLRISYENYEK', 'CCI OLMAKARIKARA', 'CCI AMBOSELI', 'CCI EMISIGIYO',
                'CCI MATIKU', "CCI NANING'O", 'CCI KIUANI', 'CCI KITENGELA',
                'CCI MASAMUKYE', 'CCI NABLA'
            ],
            'CCI-MWD-KLB' => [
                'CCI KALAMBA', 'CCI UPENDO', 'CCI MAKUTANO', 'CCI MATILIKU',
                'CCI MATHANGUNI', 'CCI KIKUI', 'CCI KALAANI', 'CCI KIKWASUNI',
                'CCI KYAU', 'CCI WELOVEA', 'CCI YUMBANI', 'CCI MUTULANI',
                'CCI ISAMBANI', 'CCI KAWALA'
            ],
            'CCI-MWD-MML' => [
                'CCI A/LIFE', 'CCI KITHEINI', 'CCI BHC', 'CCI KALUMBI',
                'CCI MUTITU', 'CCI MATWIKU', 'CCI MWAANI', 'CCI MALINDI',
                'CCI KATULYE', 'CCI MUANGINI', 'CCI SINAI'
            ],
            'CCI-MWD-KLG' => [
                'CCI MITINI', 'CCI WAUTU', 'CCI KYAMBEKE', 'CCI NDIANI',
                'CCI GRACE VALLEY', 'CCI IIANI', 'CCI KALIVIA', 'CCI BEULAH',
                'CCI NZUKINI', 'CCI ENGAVU', 'CCI KITHIONI', 'CCI BETHSAIDA'
            ],
            'CCI-MWD-KJS' => [
                'CCI EMALI', 'CCI KIMANA', 'CCI SIMBA CEMENT', 'CCI LOITOKITOK',
                'CCI MAKINDU', 'CCI ISINET', 'CCI MAKINDU', 'CCI NDUNGUNI'
            ],
        ];

        // Towns for random selection
        $towns = [
            'CCI-MWD-SHR' => ['Sultan Hamud', 'Emali', 'Makindu', 'Kibwezi'],
            'CCI-MWD-KLB' => ['Kalamba', 'Wote', 'Kathonzweni'],
            'CCI-MWD-MML' => ['Mumela', 'Wote', 'Kathonzweni'],
            'CCI-MWD-KLG' => ['Kilungu', 'Wote'],
            'CCI-MWD-KJS' => ['Emali', 'Loitokitok', 'Kimana', 'Makindu'],
        ];

        $totalCreated = 0;
        $establishmentDates = ['2005-01-15', '2008-06-20', '2010-03-10', '2012-08-05', '2015-11-25', '2018-02-14'];

        // === STEP 4: CREATE CHURCHES ===
        foreach ($churchesData as $regionCode => $churches) {
            $region = $regionObjects[$regionCode];
            $regionName = $regions[$regionCode];

            $this->command->info("📍 Creating churches for {$regionName}...");

            foreach ($churches as $index => $churchName) {
                $churchNumber = str_pad($index + 1, 3, '0', STR_PAD_LEFT);
                $churchCode = "{$regionCode}-{$churchNumber}";

                // Clean church name for email/slug
                $cleanName = strtolower(str_replace(['CCI ', ' ', '/', "'"], ['', '', '', ''], $churchName));

                try {
                    $church = Territory::create([
                        'name' => $churchName,
                        'code' => $churchCode,
                        'territory_type' => TerritoryType::CHURCH,
                        'parent_territory_id' => $region->id,
                        'level' => 3,
                        'is_active' => true,
                        'address' => 'P.O. Box ' . rand(1, 999) . ', ' . $towns[$regionCode][array_rand($towns[$regionCode])],
                        'phone' => '+2547' . str_pad(rand(10000000, 99999999), 8, '0', STR_PAD_LEFT),
                        'email' => $cleanName . '@makueniwestdiocese.or.ke',
                        'town' => $towns[$regionCode][array_rand($towns[$regionCode])],
                        'county' => $regionCode === 'CCI-MWD-KJS' ? 'Kajiado' : 'Makueni',
                        'latitude' => -1.8 - (rand(50, 200) / 1000),
                        'longitude' => 37.6 + (rand(50, 150) / 1000),
                        'established_date' => Carbon::parse($establishmentDates[array_rand($establishmentDates)]),
                        'description' => "CCI Church community serving the {$towns[$regionCode][array_rand($towns[$regionCode])]} area",
                        'metadata' => [
                            'denomination' => 'Christian Church International',
                            'service_times' => [
                                'sunday_morning' => '09:00',
                                'sunday_evening' => '16:00',
                                'wednesday_prayer' => '18:00',
                            ],
                            'facilities' => ['sanctuary', 'fellowship_hall'],
                            'membership_estimate' => rand(50, 500),
                        ],
                    ]);

                    $totalCreated++;

                } catch (\Exception $e) {
                    $this->command->error("   ❌ Failed to create {$churchName}: " . $e->getMessage());
                }
            }

            $this->command->info("   ✅ Created " . count($churches) . " churches for {$regionName}");
            $this->command->info('');
        }

        // === FINAL SUMMARY ===
        $this->command->info('');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('✅ CHURCHES SEEDING COMPLETED!');
        $this->command->info(str_repeat('=', 70));

        // Get actual counts per region
        foreach ($regionObjects as $code => $region) {
            $count = Territory::where('parent_territory_id', $region->id)
                ->where('territory_type', TerritoryType::CHURCH)
                ->count();
            $this->command->info("   {$regions[$code]}: {$count} churches");
        }

        $finalTotal = Territory::where('territory_type', TerritoryType::CHURCH)->count();

        $this->command->info('');
        $this->command->info("📊 TOTAL CHURCHES CREATED: {$finalTotal}");

        if ($finalTotal === 72) {
            $this->command->info('');
            $this->command->info('✅ SUCCESS: All 72 churches created correctly!');
            $this->command->info('');
            $this->command->info('🎯 Territory structure complete!');
            $this->command->info('   - 1 Diocese');
            $this->command->info('   - 5 Regions');
            $this->command->info('   - 72 Churches');
        } else {
            $this->command->warn("⚠️  Expected 72 churches but created {$finalTotal}");
        }
    }
}
