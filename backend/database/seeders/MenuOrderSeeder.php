<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Submodule;
use Illuminate\Database\Seeder;

/**
 * The order of each module's pages on the menu (the sidebar and the tab bar)
 * for the modules whose own seeders don't set it: the main page first, then
 * what is done most (record, add), then the views, reports last. Accounting,
 * the People & care modules and Settings order their own pages.
 *
 * Module name => page titles in order, at every level the module exists;
 * a page not listed keeps 0 and shows after the listed ones, A-Z.
 *
 * Idempotent - safe to re-run.
 */
class MenuOrderSeeder extends Seeder
{
    private const ORDER = [
        // Church (and the same modules at the region and diocese)
        'Demographics & Growth' => ['Growth Overview', 'Record Demographics', 'Monthly Statistics', 'Spiritual Activities', 'Growth Analytics', 'Reports'],
        'Monthly reports' => ['Monthly reports', 'Write our report'],
        'Attendance' => ['Attendance Overview', 'Record Attendance', 'Service Attendance', 'Ministry Attendance', 'Special Events Attendance', 'Attendance Analytics', 'Attendance Reports'],
        'Budgets' => ['Overview', 'Budgets', 'New budget', 'Income', 'Expenses', 'Contributions', "Churches' budgets", 'Regions and churches', 'Reports'],
        'Calendar' => ['Calendar', 'CCI national calendar', 'New date'],
        'Events' => ['Events', 'New event'],
        'Initiatives' => ['Initiatives', 'New initiative'],
        'Messages' => ['Inbox', 'Send a message', 'Campaigns', 'Templates', 'Message log'],

        // Diocese
        'Diocese Dashboard' => ['Key Metrics Cards', 'Diocese Trends Charts', 'Regional Comparison', 'Financial Summary', 'Compliance Dashboard', 'Map View'],
        'Demographics Analytics' => ['Overview', 'Demographics Summary', 'Growth Analysis', 'Comparative Analysis', 'Demographic Heatmap', 'Demographic Forecasting'],
        'Diocese Reports & Analytics' => ['Pre-configured Reports', 'Custom Report Builder', 'Scheduled Reports', 'Real-time Analytics', 'Reports Archive'],
        'All Churches Overview' => ['Churches Summary Statistics', 'Church List Management', 'Church Search & Filter', 'Churches Map View', 'Church Comparison Tool', 'Bulk Actions'],
        'Individual Church Details' => ['Church Basic Information', 'Demographics Dashboard', 'Financial Summary', 'Events Attendance', 'Initiatives Participation', 'Church Performance Score'],
        'Diocese Compliance Monitoring' => ['Compliance Overview', 'Compliance Management', 'Compliance Analytics', 'Compliance Reports'],

        // Region
        'Conflict Resolution' => ['Church Disputes', 'Leadership Conflicts', 'Member Grievances', 'Mediation Sessions', 'Disciplinary Actions'],
        'Pastoral Development' => ['Training Programs', 'Continuing Education', 'Mentorship Coordination', 'Performance Evaluation', 'Pastoral Transfers'],
        'Resource Management' => ['Financial Resources', 'Material Distribution'],
    ];

    public function run(): void
    {
        $this->command?->info('🧭 MENU ORDER - each module\'s pages in a sensible order');
        $set = 0;
        foreach (self::ORDER as $name => $titles) {
            foreach (Module::where('name', $name)->pluck('id') as $moduleId) {
                $place = 0;
                foreach ($titles as $title) {
                    $page = Submodule::where('module_id', $moduleId)->where('title', $title)->first();
                    if (! $page) {
                        continue;
                    }
                    $place++;
                    if ((int) $page->order !== $place) {
                        Submodule::withoutAuditing(fn () => $page->forceFill(['order' => $place])->save());
                        $set++;
                    }
                }
            }
        }
        $this->command?->info("   ✅ {$set} page(s) given their place");
    }
}
