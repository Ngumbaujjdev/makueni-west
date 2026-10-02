<?php

namespace Tests\Feature\Financial;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every level runs its own budgets; viewing goes top to bottom, read-only
 * (docs/specs/budgets-spec.md - acceptance criteria 1, 6, 7, 8).
 */
class BudgetAccessTest extends TestCase
{
    use BuildsBudgetWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
    }

    private function body(array $extra = []): array
    {
        return ['year' => 2026, 'month' => 4, 'lines' => [['budget_line_id' => $this->churchLine->id, 'amount' => 5000], ['budget_line_id' => $this->incomeLine->id, 'amount' => 9000]], ...$extra];
    }

    public function test_a_pastor_creates_a_budget_for_their_own_church_whatever_territory_is_named(): void
    {
        Sanctum::actingAs($this->pastor);

        $this->postJson('/api/budgets', $this->body(['territory_id' => $this->farChurch->id, 'territory_type' => 'diocese']))
            ->assertCreated()
            ->assertJsonPath('data.budget.place.id', $this->myChurch->id)
            ->assertJsonPath('data.budget.place.type', 'church')
            ->assertJsonPath('data.budget.period_label', 'April 2026')
            ->assertJsonPath('data.budget.in_planned', 9000)
            ->assertJsonPath('data.budget.out_planned', 5000)
            ->assertJsonPath('data.budget.left_planned', 4000);
    }

    public function test_a_pastor_lists_only_their_own_churchs_budgets(): void
    {
        $mine = $this->budgetFor($this->myChurch);
        $this->budgetFor($this->otherChurch);
        Sanctum::actingAs($this->pastor);

        $this->getJson('/api/budgets')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('view_only', false);
    }

    public function test_a_church_never_sees_sideways_or_upwards(): void
    {
        $sibling = $this->budgetFor($this->otherChurch);
        $region = $this->budgetFor($this->region);
        $diocese = $this->budgetFor($this->diocese);
        Sanctum::actingAs($this->pastor);

        foreach ([$sibling, $region, $diocese] as $budget) {
            $this->getJson("/api/budgets/{$budget->id}")->assertForbidden();
            $this->getJson("/api/budgets/{$budget->id}/history")->assertForbidden();
        }
        $this->getJson("/api/budgets?territory_id={$this->region->id}")->assertForbidden();
    }

    /** The approval-era and old settings routes are gone (phase 6); Demographics' two lookups stay. */
    public function test_the_old_budget_routes_are_gone(): void
    {
        $budget = $this->budgetFor($this->myChurch);
        Sanctum::actingAs($this->bishop);

        foreach ([
            ['GET', "/api/budgets/{$budget->id}/logs"],
            ['GET', "/api/budgets/{$budget->id}/line-items"],
            ['GET', "/api/budgets/{$budget->id}/summary"],
            ['POST', "/api/budgets/{$budget->id}/deductions"],
            ['GET', '/api/budget-deductions'],
            ['GET', '/api/budget-lines'],
            ['GET', '/api/budget-categories'],
            ['GET', '/api/budget-logs/recent'],
            ['POST', '/api/budget-types'],
        ] as [$method, $url]) {
            $this->assertContains($this->json($method, $url)->status(), [404, 405], "{$method} {$url}");
        }
        $this->getJson('/api/budget-types')->assertOk();
        $this->getJson('/api/budget-periods')->assertOk();
    }

    public function test_a_region_prepares_its_own_budget_and_never_sees_another_region(): void
    {
        $theirs = $this->budgetFor($this->otherRegion);
        $farChurch = $this->budgetFor($this->farChurch);
        Sanctum::actingAs($this->overseer);

        $this->postJson('/api/budgets', ['year' => 2026, 'month' => null, 'lines' => [['budget_line_id' => $this->allLine->id, 'amount' => 70000]]])
            ->assertCreated()->assertJsonPath('data.budget.place.type', 'region')
            ->assertJsonPath('data.budget.place.id', $this->region->id)
            ->assertJsonPath('data.budget.period_label', 'Whole of 2026');
        $this->getJson("/api/budgets/{$theirs->id}")->assertForbidden();
        $this->getJson("/api/budgets/{$farChurch->id}")->assertForbidden();
    }

    public function test_a_region_can_look_at_its_churches_but_not_change_them(): void
    {
        $church = $this->budgetFor($this->myChurch);
        Sanctum::actingAs($this->overseer);

        $this->getJson("/api/budgets/{$church->id}")->assertOk()
            ->assertJsonPath('data.view_only', true)->assertJsonPath('data.can.edit', false);
        $this->getJson("/api/budgets?territory_id={$this->myChurch->id}")->assertOk()
            ->assertJsonPath('view_only', true)->assertJsonCount(1, 'data');
        $this->putJson("/api/budgets/{$church->id}", $this->body())->assertForbidden();
        $this->postJson("/api/budgets/{$church->id}/start")->assertForbidden();
        $this->deleteJson("/api/budgets/{$church->id}")->assertForbidden();
    }

    public function test_the_diocese_looks_at_every_region_and_church_read_only(): void
    {
        $region = $this->budgetFor($this->otherRegion);
        $church = $this->budgetFor($this->farChurch);
        Sanctum::actingAs($this->bishop);

        $this->getJson("/api/budgets/{$region->id}")->assertOk()->assertJsonPath('data.view_only', true);
        $this->getJson("/api/budgets/{$church->id}")->assertOk()->assertJsonPath('data.view_only', true);
        $this->getJson("/api/budgets/{$church->id}/history")->assertOk();
        $this->postJson("/api/budgets/{$church->id}/start")->assertForbidden();
    }

    public function test_a_user_without_the_prepare_permission_cannot_create(): void
    {
        $reader = $this->userWithRole('secretary', 'Test Secretary', 'church', $this->myChurch->id, ['church.budgets.budgets.read']);
        Sanctum::actingAs($reader);

        $this->getJson('/api/budgets')->assertOk();
        $this->postJson('/api/budgets', $this->body())->assertForbidden();
        $this->getJson('/api/budgets/form')->assertForbidden();
    }

    public function test_someone_with_two_roles_acts_in_the_one_they_chose(): void
    {
        // The pastor also sits on the region's committee (a secondary assignment).
        $this->userWithRole('pastor', 'Test Committee', 'region', $this->region->id, ['region.budgets.budgets.read', 'region.budgets.below.read'], 'secondary');
        $regionAssignment = $this->pastor->activeAssignments()->where('territory_id', $this->region->id)->first();
        $sibling = $this->budgetFor($this->otherChurch);
        Sanctum::actingAs($this->pastor);

        // As the pastor: the sibling church is off limits
        $this->getJson("/api/budgets/{$sibling->id}")->assertForbidden();
        // As the region committee member: it's a church below, read-only
        $this->withHeader('X-Assignment-Id', (string) $regionAssignment->id)
            ->getJson("/api/budgets/{$sibling->id}")->assertOk()->assertJsonPath('data.view_only', true);
    }
}
