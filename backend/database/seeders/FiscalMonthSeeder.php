<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FiscalMonth;

class FiscalMonthSeeder extends Seeder
{
    /**
     * Seed the static 12 months.
     */
    public function run(): void
    {
        $months = [
            ['number' => 1, 'name' => 'January', 'short_name' => 'Jan'],
            ['number' => 2, 'name' => 'February', 'short_name' => 'Feb'],
            ['number' => 3, 'name' => 'March', 'short_name' => 'Mar'],
            ['number' => 4, 'name' => 'April', 'short_name' => 'Apr'],
            ['number' => 5, 'name' => 'May', 'short_name' => 'May'],
            ['number' => 6, 'name' => 'June', 'short_name' => 'Jun'],
            ['number' => 7, 'name' => 'July', 'short_name' => 'Jul'],
            ['number' => 8, 'name' => 'August', 'short_name' => 'Aug'],
            ['number' => 9, 'name' => 'September', 'short_name' => 'Sep'],
            ['number' => 10, 'name' => 'October', 'short_name' => 'Oct'],
            ['number' => 11, 'name' => 'November', 'short_name' => 'Nov'],
            ['number' => 12, 'name' => 'December', 'short_name' => 'Dec'],
        ];

        foreach ($months as $month) {
            FiscalMonth::firstOrCreate(
                ['number' => $month['number']],
                $month
            );
        }

        $this->command->info('✅ Seeded 12 fiscal months');
    }
}
