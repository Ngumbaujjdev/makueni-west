<?php

namespace Tests\Feature\Accounting;

use App\Approval\Services\ApprovalService;
use App\Models\ApprovalRequest;
use App\Models\ApprovalWorkflow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Approval rules at every level (docs/specs/accounting-spec.md, A4b): a church
 * or region sets who approves its own payments, up to the diocese's ceiling;
 * the nearest owner's rule wins; each place changes only its own; the map and
 * "Try an amount" read the rules without creating anything.
 */
class ApprovalRulesEveryLevelTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private User $dfo;

    private User $churchRules;

    private User $regionRules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        $this->dfo = $this->userWithRole('dforules', 'Diocese Finance Officer', 'diocese', $this->diocese->id, $this->perms('diocese', ['read', 'rules']));
        $this->churchRules = $this->userWithRole('chrules', 'Church Treasurer', 'church', $this->myChurch->id, $this->perms('church', ['read', 'rules']));
        $this->regionRules = $this->userWithRole('rgrules', 'Regional Treasurer', 'region', $this->region->id, $this->perms('region', ['read', 'rules']));
        // The standard church rule: the pastor approves anything.
        $this->saveRule($this->dfo, ['name' => 'Church - any amount', 'level' => 'church', 'stages' => [$this->stage('Pastor', 'Senior Pastor')]])->assertCreated();
    }

    private function stage(string $name, string $role, string $onEmpty = 'block'): array
    {
        return ['name' => $name, 'type' => 'single', 'on_empty' => $onEmpty, 'sla_hours' => 48, 'steps' => [['resolver_type' => 'role_here', 'resolver_config' => ['roles' => [$role]]]]];
    }

    private function saveRule(User $as, array $body): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($as);
        $r = $this->postJson('/api/approvals/workflows', $body + ['subject_type' => '*', 'is_active' => true]);
        $this->app['auth']->forgetGuards();

        return $r;
    }

    private function ruleFor($place, float $amount): ?string
    {
        return app(ApprovalService::class)->findWorkflow('payment_voucher', $place, ['amount' => $amount, 'level' => $place->territory_type->value])?->name;
    }

    public function test_a_church_rule_wins_for_that_church_up_to_the_ceiling(): void
    {
        $this->saveRule($this->churchRules, ['name' => 'Our small payments', 'amount_max' => 20000, 'stages' => [$this->stage('Treasurer', 'Church Treasurer')]])->assertCreated();
        $own = ApprovalWorkflow::where('name', 'Our small payments')->firstOrFail();
        $this->assertSame([$this->myChurch->id, 'church'], [(int) $own->territory_id, $own->level]);

        $this->assertSame('Our small payments', $this->ruleFor($this->myChurch, 10000));
        $this->assertSame('Church - any amount', $this->ruleFor($this->myChurch, 30000), 'above its band, the standard rule');
        $this->assertSame('Church - any amount', $this->ruleFor($this->otherChurch, 10000), 'another church keeps the standard');

        // Past the ceiling (KES 200,000 by default) a place can't set its own.
        $this->saveRule($this->churchRules, ['name' => 'Big', 'amount_min' => 20000, 'amount_max' => 300000, 'stages' => [$this->stage('Treasurer', 'Church Treasurer')]])
            ->assertStatus(422)->assertJsonPath('errors.amount_max.0', fn ($m) => str_contains($m, "the diocese's rules apply"));
        $this->saveRule($this->churchRules, ['name' => 'Everything', 'amount_min' => 20000, 'stages' => [$this->stage('Treasurer', 'Church Treasurer')]])->assertStatus(422);
        // And two of its own rules can't cover the same amount.
        $this->saveRule($this->churchRules, ['name' => 'Tiny', 'amount_max' => 5000, 'stages' => [$this->stage('Treasurer', 'Church Treasurer')]])
            ->assertStatus(422)->assertJsonPath('errors.amount_min.0', fn ($m) => str_contains($m, 'overlaps "Our small payments"'));
    }

    public function test_a_region_rule_for_its_churches_reaches_them_but_not_another_region(): void
    {
        $this->saveRule($this->regionRules, ['name' => 'Region churches - small', 'reach' => 'below', 'level' => 'church', 'amount_max' => 30000, 'stages' => [$this->stage('Pastor', 'Senior Pastor')]])->assertCreated();
        $this->assertSame('below', ApprovalWorkflow::where('name', 'Region churches - small')->value('reach'));
        $this->assertSame('Region churches - small', $this->ruleFor($this->otherChurch, 25000));
        $this->assertSame('Church - any amount', $this->ruleFor($this->farChurch, 25000), 'a church in another region');
        // A church's own rule still beats the region's.
        $this->saveRule($this->churchRules, ['name' => 'Ours', 'amount_max' => 20000, 'stages' => [$this->stage('Treasurer', 'Church Treasurer')]])->assertCreated();
        $this->assertSame('Ours', $this->ruleFor($this->myChurch, 10000));
        $this->assertSame('Region churches - small', $this->ruleFor($this->myChurch, 25000));
    }

    public function test_each_place_changes_only_its_own(): void
    {
        $this->saveRule($this->churchRules, ['name' => 'Ours', 'amount_max' => 20000, 'stages' => [$this->stage('Treasurer', 'Church Treasurer')]])->assertCreated();
        $standard = ApprovalWorkflow::where('name', 'Church - any amount')->firstOrFail();
        $ours = ApprovalWorkflow::where('name', 'Ours')->firstOrFail();

        Sanctum::actingAs($this->churchRules);
        $list = collect($this->getJson('/api/approvals/workflows')->assertOk()->json('data.items'));
        $this->assertSame(['own', true], [$list->firstWhere('id', $ours->id)['group'], $list->firstWhere('id', $ours->id)['editable']]);
        $this->assertSame(['standard', false], [$list->firstWhere('id', $standard->id)['group'], $list->firstWhere('id', $standard->id)['editable']]);
        $this->putJson("/api/approvals/workflows/{$standard->id}", ['name' => 'Mine now', 'subject_type' => '*', 'stages' => [$this->stage('Treasurer', 'Church Treasurer')]])->assertForbidden();
        $this->getJson('/api/approvals/workflows?territory_id='.$this->otherChurch->id)->assertForbidden();
        $this->app['auth']->forgetGuards();

        // Someone who only approves sees the rules, but can't change them.
        Sanctum::actingAs($this->authoriser);
        $this->getJson('/api/approvals/workflows')->assertOk()->assertJsonPath('data.can.manage', false);
        $this->postJson('/api/approvals/workflows', ['name' => 'X', 'subject_type' => '*', 'amount_max' => 1000, 'stages' => [$this->stage('Treasurer', 'Church Treasurer')]])->assertForbidden();
        $this->app['auth']->forgetGuards();

        // The diocese changes the standard rule, and sees the church's own.
        Sanctum::actingAs($this->dfo);
        $this->putJson("/api/approvals/workflows/{$standard->id}", ['name' => 'Church - any amount', 'subject_type' => '*', 'level' => 'church', 'stages' => [$this->stage('Pastor', 'Senior Pastor')]])->assertOk();
        $this->assertSame('below', collect($this->getJson('/api/approvals/workflows')->json('data.items'))->firstWhere('id', $ours->id)['group']);
    }

    public function test_try_an_amount_names_who_would_sign_and_creates_nothing(): void
    {
        \App\Models\Role::firstOrCreate(['name' => 'Church Committee Member', 'guard_name' => 'web'], ['territory_level' => 'church']);
        $this->saveRule($this->churchRules, ['name' => 'Ours', 'amount_max' => 20000, 'stages' => [$this->stage('Pastor first', 'Senior Pastor'), $this->stage('Committee', 'Church Committee Member', 'skip')]])->assertCreated();
        Sanctum::actingAs($this->churchRules);
        $t = $this->getJson('/api/approvals/try?subject=payment_voucher&amount=10000')->assertOk()->json('data');
        $this->assertSame('Ours', $t['rule']['name']);
        $this->assertSame('Our own', $t['rule']['owner']);
        $this->assertSame([$this->authoriser->full_name], $t['stages'][0]['people']);
        $this->assertStringContainsString('skipped', $t['stages'][1]['note']);
        $this->assertSame('Diocese standard', $this->getJson('/api/approvals/try?subject=payment_voucher&amount=50000')->json('data.rule.owner'));
        $this->getJson('/api/approvals/try?subject=payment_voucher')->assertStatus(422);
        $this->assertSame(0, ApprovalRequest::count());

        $m = $this->getJson('/api/approvals/map?subject=payment_voucher')->assertOk()->json('data');
        $this->assertContains('Pastor first', collect($m['rows'])->pluck('name')->all());
        $this->assertSame(2, $m['detected']['rules']);
        $this->assertTrue(collect($m['blocks'])->contains(fn ($b) => $b['rule'] === 'Ours' && $b['owner'] === 'Our own'));
        $this->assertTrue(collect($m['segments'])->contains(fn ($s) => $s['standard_only']), 'amounts above the ceiling are the diocese\'s');
        $this->assertTrue($m['can']['manage']);
    }

    public function test_the_map_asks_about_what_is_waiting_including_a_payroll_run(): void
    {
        $run = \App\Models\PayrollRun::create(['territory_id' => $this->myChurch->id, 'month' => '2026-10', 'status' => 'submitted']);
        $req = ApprovalRequest::create(['subject_type' => 'payroll_run', 'subject_id' => $run->id, 'territory_id' => $this->myChurch->id, 'requested_by' => $this->treasurer->id, 'workflow_name' => 'Church - any amount', 'status' => 'pending']);
        \App\Models\ApprovalRequestStage::create(['request_id' => $req->id, 'sequence' => 1, 'name' => 'Pastor', 'type' => 'single', 'on_reject' => 'terminate', 'status' => 'blocked', 'blocked_reason' => 'Nobody holds the role for this stage.']);
        Sanctum::actingAs($this->churchRules);
        $m = $this->getJson('/api/approvals/map?subject=payroll_run')->assertOk()->json('data');
        $this->assertSame('Why is Payroll October 2026 stuck?', $m['questions'][0]['text']);
        $this->assertSame(['payroll', $run->id], [$m['questions'][0]['type'], $m['questions'][0]['id']]);
        $this->assertSame([1, 1], [$m['detected']['waiting'], $m['detected']['blocked']]);
        $this->assertSame('blocked', collect($m['checks'])->first()['action'] ?? null, 'a stuck approval is the first thing to fix');
    }
}
