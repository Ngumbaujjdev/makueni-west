<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Territory;
use App\Enums\TerritoryType;
use Carbon\Carbon;

class SubregionsSeeder extends Seeder
{
    public function run(): void
    {
        $localTowns = ['Wote', 'Kathonzweni', 'Makindu', 'Kibwezi', 'Emali', 'Sultan Hamud'];

        // Only some regions have subregions
        $subregionsData = [
            'CCI-MWD-KLB' => [ // Kalamba Region
                ['name' => 'Kilungu Subregion', 'code' => 'SR01'],
                ['name' => 'Kalamba Central Subregion', 'code' => 'SR02'],
                ['name' => 'Mbitini Subregion', 'code' => 'SR03'],
            ],
            'CCI-MWD-SHR' => [ // Sultan Hamud Region
                ['name' => 'Sultan Hamud Central Subregion', 'code' => 'SR01'],
                ['name' => 'Kibwezi Subregion', 'code' => 'SR02'],
            ],
        ];

        foreach ($subregionsData as $regionCode => $subregions) {
            $region = Territory::where('code', $regionCode)->first();

            foreach ($subregions as $subregion) {
                Territory::create([
                    'name' => $subregion['name'],
                    'code' => "{$regionCode}-{$subregion['code']}",
                    'territory_type' => TerritoryType::SUBREGION,
                    'parent_territory_id' => $region->id,
                    'level' => 3,
                    'is_active' => true,
                    'address' => 'P.O. Box ' . rand(50, 200) . ', ' . $localTowns[array_rand($localTowns)],
                    'phone' => '+2547' . str_pad(rand(10000000, 99999999), 8, '0', STR_PAD_LEFT),
                    'email' => strtolower(str_replace(' ', '', $subregion['name'])) . '@makueniwestdiocese.or.ke',
                    'town' => $localTowns[array_rand($localTowns)],
                    'county' => 'Makueni',
                    'latitude' => -1 - (rand(700, 1000) / 1000),
                    'longitude' => 37 + (rand(500, 800) / 1000),
                    'established_date' => Carbon::parse('2012-06-01'),
                    'description' => $subregion['name'] . ' subregion',
                ]);
            }
        }

        $this->command->info('Successfully seeded subregions');
    }
}
