<?php

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Seeder;

class MuteNonDemographicsChurchModulesSeeder extends Seeder
{
    /**
     * Temporary focus-mode toggle while the Demographics module (Growth +
     * Attendance) is being built and tested: deactivates every other
     * church-tier module so the church sidebar only shows Church Dashboard,
     * Growth, and Attendance (the static Account section - My Profile, Help
     * & Support, Logout - is hardcoded in includes/sidebar.php and isn't
     * affected either way).
     *
     * Reversible: to restore, set these same module IDs' is_active back to
     * true (e.g. via a one-off tinker command or a companion restore
     * seeder) - no data is deleted, only the is_active flag is toggled.
     */
    private const MUTED_CHURCH_MODULE_IDS = [
        26, // Members
        29, // Finance - becomes Budgets (BudgetsAccessSeeder, the last phase, switches it back on)
        30, // Diocesan Contributions
        31, // Ministries
        32, // Service Management
        33, // Facility Management
        34, // Communication
        35, // Visitors
        36, // Pastoral Care
        37, // Church Reporting
    ];

    private const KEPT_ACTIVE_CHURCH_MODULE_IDS = [
        39, // Church Dashboard
        27, // Growth
        28, // Attendance
    ];

    public function run(): void
    {
        $this->command->info('🔇 FOCUS MODE: Muting non-Demographics church modules');
        $this->command->info(str_repeat('=', 70));

        $muted = 0;
        foreach (Module::whereIn('id', self::MUTED_CHURCH_MODULE_IDS)->get() as $module) {
            // Budgets is built and live (docs/specs/budgets-spec.md): never mute the module holding its pages.
            if ($module->submodules()->where('path', 'like', '/church/budget/%')->exists()) {
                $this->command->info("   🔊 Kept: {$module->name} (ID: {$module->id}) - the church's Budgets");

                continue;
            }
            // Monthly reports and Messages too - they reuse "Church Reporting" and "Communication".
            if ($module->submodules()->whereIn('path', ['/church/monthly-reports/', '/church/messages/'])->exists()) {
                $this->command->info("   🔊 Kept: {$module->name} (ID: {$module->id}) - built for the church (Monthly reports or Messages)");

                continue;
            }
            // People & care's modules are switched on and off by PeopleCareAccessSeeder, phase by phase.
            if ($module->submodules()->where(fn ($q) => $q->where('path', 'like', '/church/members/%')->orWhere('path', 'like', '/church/visitors/%')->orWhere('path', 'like', '/church/pastoral-care/%')->orWhere('path', 'like', '/church/ministries/%')->orWhere('path', 'like', '/church/facilities/%'))->exists()) {
                $this->command->info("   🔊 Left to PeopleCareAccessSeeder: {$module->name} (ID: {$module->id})");

                continue;
            }
            if ($module->is_active) {
                $module->is_active = false;
                $module->save();
                $muted++;
                $this->command->info("   🔇 Muted: {$module->name} (ID: {$module->id})");
            } else {
                $this->command->warn("   ⚠️  Already muted: {$module->name} (ID: {$module->id})");
            }
        }

        foreach (Module::whereIn('id', self::KEPT_ACTIVE_CHURCH_MODULE_IDS)->get() as $module) {
            if (! $module->is_active) {
                $module->is_active = true;
                $module->save();
                $this->command->info("   🔊 Re-activated: {$module->name} (ID: {$module->id})");
            }
        }

        $this->command->info('');
        $this->command->info("✅ Focus mode applied - {$muted} module(s) muted, ".count(self::KEPT_ACTIVE_CHURCH_MODULE_IDS).' kept active.');
        $this->command->info('   Active for church tier: Church Dashboard, Growth, Attendance');
    }
}
