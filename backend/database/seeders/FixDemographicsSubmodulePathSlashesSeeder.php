<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Submodule;

class FixDemographicsSubmodulePathSlashesSeeder extends Seeder
{
    /**
     * includes/sidebar.php's formatPath() builds every sidebar link as
     * $baseUrl . $path with no separator check ($baseUrl = '/makueni-west',
     * no trailing slash) - it only works because every submodule path in
     * the system starts with a leading slash, e.g. "/church/dashboard/".
     *
     * FixDemographicsPermissionScaffoldingSeeder, AddSubregionDemographicsReviewSeeder,
     * and AddRegionDemographicsSummarySeeder all wrote their corrected/new
     * paths without that leading slash (e.g. "church/attendance/services.php"),
     * which concatenated into a broken URL: "/makueni-westchurch/attendance/services".
     * Confirmed: exactly 18 submodules system-wide lack the leading slash,
     * all of them from those three seeders - every other submodule in the
     * system (158 of them) already follows the leading-slash convention.
     *
     * Idempotent - safe to re-run.
     */
    public function run(): void
    {
        $this->command->info('🔧 FIXING DEMOGRAPHICS SUBMODULE PATH SLASHES');
        $this->command->info(str_repeat('=', 70));

        $submodules = Submodule::where('path', 'not like', '/%')->whereNotNull('path')->get();

        if ($submodules->isEmpty()) {
            $this->command->warn('   ⚠️  No paths need fixing');
            return;
        }

        foreach ($submodules as $submodule) {
            $old = $submodule->path;
            $submodule->update(['path' => '/' . ltrim($old, '/')]);
            $this->command->info("   ✅ {$submodule->title}: \"{$old}\" -> \"{$submodule->path}\"");
        }

        $this->command->info('');
        $this->command->info("✅ DONE: {$submodules->count()} path(s) fixed.");
    }
}
