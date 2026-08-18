<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;
use App\Models\Submodule;

class FixDemographicsModuleNamingAndOrderingSeeder extends Seeder
{
    /**
     * Three IA problems flagged directly from a browser screenshot:
     *
     * 1. Module 27's name "Church Demographics & Growth" truncates ugly in
     *    the sidebar and redundantly repeats "Church" - the user is already
     *    inside the church-tier sidebar, the prefix adds nothing.
     * 2. Two visually-adjacent "Overview" labels: the module_group itself
     *    is named "Overview" (sidebar section header), and both module 27's
     *    and module 28's landing submodule are also each just called
     *    "Overview" - reads as a duplicated/confusing label stack next to
     *    Church Dashboard's "Dashboard Overview".
     * 3. Church Dashboard (module 39, number=37) sorted *after*
     *    Demographics & Growth (module 27, number=2) in the same module
     *    group, because ModuleController::getModulesForRole() orders by
     *    `number` then `name` - the dashboard should be the first thing a
     *    user sees.
     *
     * Idempotent - safe to re-run.
     */
    public function run(): void
    {
        $this->command->info('✏️  FIXING DEMOGRAPHICS MODULE NAMING & ORDERING');
        $this->command->info(str_repeat('=', 70));

        $renames = [
            ['type' => Module::class, 'id' => 27, 'field' => 'name', 'value' => 'Demographics & Growth'],
            ['type' => Module::class, 'id' => 28, 'field' => 'name', 'value' => 'Attendance'],
            ['type' => Submodule::class, 'id' => 180, 'field' => 'title', 'value' => 'Growth Overview'],
            ['type' => Submodule::class, 'id' => 181, 'field' => 'title', 'value' => 'Attendance Overview'],
        ];

        foreach ($renames as $rename) {
            $record = $rename['type']::find($rename['id']);

            if (!$record) {
                $this->command->error("   ❌ {$rename['type']} #{$rename['id']} not found");
                continue;
            }

            if ($record->{$rename['field']} === $rename['value']) {
                $this->command->warn("   ⚠️  Already correct: {$rename['value']}");
                continue;
            }

            $old = $record->{$rename['field']};
            $record->update([$rename['field'] => $rename['value']]);
            $this->command->info("   ✅ \"{$old}\" -> \"{$rename['value']}\"");
        }

        $dashboard = Module::find(39);

        if ($dashboard && $dashboard->number !== 1) {
            $old = $dashboard->number;
            $dashboard->update(['number' => 1]);
            $this->command->info("   ✅ Church Dashboard: number {$old} -> 1 (now sorts first)");
        } else {
            $this->command->warn('   ⚠️  Church Dashboard already sorts first');
        }

        $this->command->info('');
        $this->command->info('✅ DONE');
    }
}
