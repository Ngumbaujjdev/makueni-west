<?php

namespace Tests\Feature\Financial;

use App\Models\BudgetLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Budget Settings: each level's own lines, the diocese's shared ones (docs/specs/budgets-spec.md, phase 4). */
class BudgetSettingsTest extends TestCase
{
    use BuildsBudgetWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
    }

    public function test_a_church_adds_its_own_line_and_its_budget_form_offers_it(): void
    {
        Sanctum::actingAs($this->pastor);

        $id = $this->postJson('/api/budget-settings/lines', ['name' => 'Choir uniforms', 'side' => 'out'])->assertCreated()->json('data.id');

        $line = BudgetLine::find($id);
        $this->assertSame('church', $line->territory_type);
        $this->assertSame($this->myChurch->id, (int) $line->territory_id);
        $settings = $this->getJson('/api/budget-settings')->assertOk()->json('data');
        $row = collect($settings['lines'])->firstWhere('id', $id);
        $this->assertTrue($row['is_own']);
        $this->assertTrue($row['editable']);
        $this->assertSame('out', $row['side']);
        $form = $this->getJson('/api/budgets/form?year=2026&month=5')->assertOk()->json('data.lines.out');
        $this->assertContains('Choir uniforms', collect($form)->pluck('name'));
    }

    public function test_a_church_sees_the_diocese_lines_locked_and_cannot_change_them(): void
    {
        Sanctum::actingAs($this->pastor);

        $settings = $this->getJson('/api/budget-settings')->assertOk()->json('data');
        $rent = collect($settings['lines'])->firstWhere('name', 'Church Rent');
        $this->assertFalse($rent['editable']);
        $this->assertSame('Every church', $rent['shared_label']);
        $this->assertNull(collect($settings['lines'])->firstWhere('name', 'Diocese Office'));

        $this->putJson("/api/budget-settings/lines/{$this->churchLine->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/budget-settings/lines/{$this->churchLine->id}")->assertForbidden();
    }

    public function test_a_region_keeps_its_own_lines_apart_from_a_church(): void
    {
        Sanctum::actingAs($this->overseer);
        $id = $this->postJson('/api/budget-settings/lines', ['name' => 'Regional rally', 'side' => 'out'])->assertCreated()->json('data.id');
        $this->assertSame('region', BudgetLine::find($id)->territory_type);

        Sanctum::actingAs($this->pastor);
        $this->assertNull(collect($this->getJson('/api/budget-settings')->json('data.lines'))->firstWhere('id', $id));
        $this->putJson("/api/budget-settings/lines/{$id}", ['name' => 'Mine now'])->assertForbidden();
    }

    public function test_the_diocese_shares_a_line_with_every_church(): void
    {
        Sanctum::actingAs($this->bishop);
        $id = $this->postJson('/api/budget-settings/lines', ['name' => 'Diocese share', 'side' => 'out', 'share_with' => 'church'])->assertCreated()->json('data.id');
        $line = BudgetLine::find($id);
        $this->assertNull($line->territory_id);
        $this->assertSame('church', $line->territory_scope);

        Sanctum::actingAs($this->pastor);
        $row = collect($this->getJson('/api/budget-settings')->json('data.lines'))->firstWhere('id', $id);
        $this->assertFalse($row['editable']);
    }

    public function test_a_used_line_is_switched_off_not_deleted(): void
    {
        Sanctum::actingAs($this->pastor);
        $id = $this->postJson('/api/budget-settings/lines', ['name' => 'Choir uniforms', 'side' => 'out'])->assertCreated()->json('data.id');
        $this->budgetFor($this->myChurch, 'draft', [BudgetLine::find($id)]);

        $this->deleteJson("/api/budget-settings/lines/{$id}")->assertStatus(422);
        $this->putJson("/api/budget-settings/lines/{$id}", ['side' => 'in'])->assertStatus(422);
        $this->putJson("/api/budget-settings/lines/{$id}", ['is_active' => false])->assertOk();
        $this->assertFalse((bool) BudgetLine::find($id)->is_active);

        $unused = $this->postJson('/api/budget-settings/lines', ['name' => 'Spare', 'side' => 'in'])->json('data.id');
        $this->deleteJson("/api/budget-settings/lines/{$unused}")->assertOk();
    }

    public function test_a_role_without_settings_cannot_open_or_change_them(): void
    {
        $secretary = $this->userWithRole('secretary', 'Test Secretary', 'church', $this->myChurch->id, ['church.budgets.budgets.read']);
        Sanctum::actingAs($secretary);

        $this->getJson('/api/budget-settings')->assertForbidden();
        $this->postJson('/api/budget-settings/lines', ['name' => 'X', 'side' => 'out'])->assertForbidden();
    }
}
