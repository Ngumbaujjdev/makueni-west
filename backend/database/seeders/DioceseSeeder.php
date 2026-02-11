<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Territory;
use App\Enums\TerritoryType;
use Carbon\Carbon;

class DioceseSeeder extends Seeder
{
    public function run(): void
    {
        // Create Global Organization (root level) - CCI
        $global = Territory::create([
            'name' => 'Christian Church International Kenya',
            'code' => 'CCI-KENYA',
            'territory_type' => TerritoryType::GLOBAL,
            'parent_territory_id' => null,
            'level' => 0,
            'is_active' => true,
            'address' => 'P.O. Box 12345, Nairobi',
            'phone' => '+254700123456',
            'email' => 'info@ccikenya.or.ke',
            'town' => 'Nairobi',
            'county' => 'Nairobi',
            'established_date' => Carbon::parse('1990-01-01'),
            'description' => 'Christian Church International Kenya - National Organization',
        ]);

        // Create Makueni West Diocese
        Territory::create([
            'name' => 'Makueni West Diocese',
            'code' => 'CCI-MWD',
            'territory_type' => TerritoryType::DIOCESE,
            'parent_territory_id' => $global->id,
            'level' => 1,
            'is_active' => true,
            'address' => 'P.O. Box 234, Wote',
            'phone' => '+254712345678',
            'email' => 'info@makueniwestdiocese.or.ke',
            'town' => 'Wote',
            'county' => 'Makueni',
            'latitude' => -1.8861,
            'longitude' => 37.6288,
            'established_date' => Carbon::parse('2010-03-15'),
            'description' => 'Makueni West Diocese covering western parts of Makueni County under CCI',
        ]);

        $this->command->info('Successfully seeded Diocese structure');
    }
}
