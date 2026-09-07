<?php

namespace Database\Seeders;

use App\Models\SubSubmodule;
use Illuminate\Database\Seeder;

class RetireDeadDemographicsSubSubmodulesSeeder extends Seeder
{
    /**
     * Fixes a real sidebar bug: includes/sidebar.php's renderSubmodules()
     * only renders a submodule as a clickable link when it has zero
     * children - a submodule with children renders as a toggle-only
     * `href="javascript:void(0);"` instead, discarding its own real page
     * path. "Demographics Tracking" (submodule 125,
     * demographics-tracking.php) and "Spiritual Activities" (submodule 126,
     * spiritual-activities.php) are both real, working pages, but each has
     * dead-link children left over from the original ChurchSystemSeeder
     * scaffolding (routes like /demographics/tracking/men with no page ever
     * built behind them) - so both pages are currently unreachable from the
     * sidebar.
     *
     * Soft-disabling these 9 rows (not deleting them) is enough:
     * ModuleController::getModulesForRole()'s eager-load already scopes
     * `subSubmodules()->active()`, so is_active=false alone excludes them
     * from the sidebar response regardless of what any role's permissions
     * still reference. All 36 permissions under these 9 rows (create/read/
     * update/export each) are actively granted to 5 real roles (Global
     * Administrator, Senior Pastor, Associate Pastor, Church Secretary,
     * Church Administrator) - soft-disable leaves every one of those grants
     * intact, unlike a hard delete which would cascade-delete them.
     *
     * Reversible: flip these same IDs' is_active back to true to restore
     * (they'd need real pages behind them first, or the sidebar bug returns
     * for their parent submodule).
     */
    private const DEAD_SUB_SUBMODULE_IDS = [
        // Demographics Tracking (submodule 125) - already collected in one
        // monthly/half-yearly/yearly submission, not separate pages
        67, // Sunday School Enrollment
        68, // Youth Ministry Tracking
        69, // Men's Fellowship
        70, // Women's Ministry
        71, // Age Group Distribution
        // Spiritual Activities (submodule 126) - now real tabs on
        // spiritual-activities.php instead of separate pages
        72, // Baptism Records
        73, // Communion Participation
        74, // New Converts Tracking
        75, // Member Departures
    ];

    public function run(): void
    {
        $this->command->info('🔇 Retiring dead Demographics sub-submodule links');
        $this->command->info(str_repeat('=', 70));

        $retired = 0;
        foreach (SubSubmodule::whereIn('id', self::DEAD_SUB_SUBMODULE_IDS)->get() as $subSubmodule) {
            if ($subSubmodule->is_active) {
                $subSubmodule->is_active = false;
                $subSubmodule->save();
                $retired++;
                $this->command->info("   🔇 Retired: {$subSubmodule->title} (ID: {$subSubmodule->id})");
            } else {
                $this->command->warn("   ⚠️  Already inactive: {$subSubmodule->title} (ID: {$subSubmodule->id})");
            }
        }

        $this->command->info('');
        $this->command->info("✅ {$retired} sub-submodule(s) retired - Demographics Tracking and Spiritual Activities now render as plain clickable sidebar links.");
    }
}
