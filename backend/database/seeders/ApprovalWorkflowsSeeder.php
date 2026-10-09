<?php

namespace Database\Seeders;

use App\Approval\Services\WorkflowBuilder;
use App\Models\ApprovalWorkflow;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The default approval rules (docs/specs/accounting-spec.md, A4) for any
 * money document - requisitions and payment vouchers - by level and amount:
 *
 *   church:  up to 50,000 the Senior Pastor; to 200,000 also a committee
 *            member; above that also the Regional Overseer
 *   region:  the Regional Overseer; then the Regional Treasurer; above
 *            200,000 the Diocese Finance Officer
 *   diocese: the Diocese Finance Officer; above 50,000 also the Bishop
 *
 * When the person asking is the approver (the pastor asks), the stage goes
 * to the level above. Chased after 48 hours, passed up after another 24.
 * Provisioned once by key: re-seeding never overwrites the diocese's edits.
 */
class ApprovalWorkflowsSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('🛡️  APPROVALS - default rules');
        $by = User::orderBy('id')->first();
        if (! $by) {
            return;
        }
        $builder = app(WorkflowBuilder::class);
        $made = 0;
        foreach ($this->rules() as $key => $rule) {
            if (ApprovalWorkflow::withTrashed()->where('key', $key)->exists()) {
                continue;
            }
            $builder->save($rule + ['key' => $key, 'subject_type' => '*', 'is_active' => true], null, $by);
            $made++;
        }
        $this->command?->info("   ✅ {$made} new rule(s)");
    }

    private function rules(): array
    {
        $stage = fn (string $name, string $type, array $roles, ?array $escalate, string $empty = 'escalate') => [
            'name' => $name, 'type' => 'single', 'on_reject' => 'terminate', 'on_empty' => $escalate ? $empty : 'block',
            'sla_hours' => 48, 'escalate_after_hours' => 24, 'escalate_to' => $escalate,
            'steps' => [['resolver_type' => $type, 'resolver_config' => ['roles' => $roles] + ($type === 'role_above' ? ['level' => $escalate['level'] ?? null] : [])]],
        ];
        $above = fn (string $role, string $level) => ['resolver_type' => 'role_above', 'resolver_config' => ['roles' => [$role], 'level' => $level]];
        $here = fn (string $role) => ['resolver_type' => 'role_here', 'resolver_config' => ['roles' => [$role]]];

        $pastor = $stage('Pastor', 'role_here', ['Senior Pastor'], $above('Regional Overseer', 'region'));
        $committee = $stage('Church committee', 'role_here', ['Church Committee Member'], $above('Regional Overseer', 'region'), 'skip');
        $region = ['name' => 'Region', 'type' => 'single', 'on_reject' => 'terminate', 'on_empty' => 'escalate', 'sla_hours' => 48, 'escalate_after_hours' => 24,
            'escalate_to' => $above('Diocese Finance Officer', 'diocese'), 'steps' => [$above('Regional Overseer', 'region')]];
        $overseer = $stage('Overseer', 'role_here', ['Regional Overseer'], $above('Diocese Finance Officer', 'diocese'));
        $regTreasurer = $stage('Regional treasurer', 'role_here', ['Regional Treasurer'], $above('Diocese Finance Officer', 'diocese'), 'skip');
        $diocese = ['name' => 'Diocese', 'type' => 'single', 'on_reject' => 'terminate', 'on_empty' => 'escalate', 'sla_hours' => 48, 'escalate_after_hours' => 24,
            'escalate_to' => $above('Bishop', 'diocese'), 'steps' => [$above('Diocese Finance Officer', 'diocese')]];
        $dfo = $stage('Finance officer', 'role_here', ['Diocese Finance Officer'], $here('Bishop'));
        $bishop = $stage('Bishop', 'role_here', ['Bishop'], $here('Diocese Treasurer'));

        return [
            'church.up-to-50k' => ['name' => 'Church - up to KES 50,000', 'level' => 'church', 'amount_max' => 50000, 'stages' => [$pastor]],
            'church.50k-200k' => ['name' => 'Church - KES 50,000 to 200,000', 'level' => 'church', 'amount_min' => 50000, 'amount_max' => 200000, 'stages' => [$pastor, $committee]],
            'church.over-200k' => ['name' => 'Church - above KES 200,000', 'level' => 'church', 'amount_min' => 200000, 'stages' => [$pastor, $committee, $region]],
            'region.up-to-50k' => ['name' => 'Region - up to KES 50,000', 'level' => 'region', 'amount_max' => 50000, 'stages' => [$overseer]],
            'region.50k-200k' => ['name' => 'Region - KES 50,000 to 200,000', 'level' => 'region', 'amount_min' => 50000, 'amount_max' => 200000, 'stages' => [$overseer, $regTreasurer]],
            'region.over-200k' => ['name' => 'Region - above KES 200,000', 'level' => 'region', 'amount_min' => 200000, 'stages' => [$overseer, $regTreasurer, $diocese]],
            'diocese.up-to-50k' => ['name' => 'Diocese - up to KES 50,000', 'level' => 'diocese', 'amount_max' => 50000, 'stages' => [$dfo]],
            'diocese.over-50k' => ['name' => 'Diocese - above KES 50,000', 'level' => 'diocese', 'amount_min' => 50000, 'stages' => [$dfo, $bishop]],
        ];
    }
}
