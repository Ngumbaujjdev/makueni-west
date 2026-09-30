<?php

namespace Tests\Feature\Financial;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A church sees the diocese's shared lines that apply to churches plus its
 * own lines, and may add, change and delete only its own.
 */
class ChurchBudgetLinesTest extends TestCase
{
    use BuildsBudgetWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
    }

    public function test_a_church_sees_shared_church_lines_and_its_own_never_another_churchs(): void
    {
        $mine = $this->ownLine($this->myChurch, 'Choir Robes');
        $theirs = $this->ownLine($this->otherChurch, 'Their Bus');
        Sanctum::actingAs($this->pastor);

        $ids = collect($this->getJson('/api/budget-lines?territory_scope=diocese')->assertOk()->json('data'))->pluck('id');

        $this->assertEqualsCanonicalizing([$this->churchLine->id, $this->allLine->id, $mine->id], $ids->all());
        $this->assertNotContains($theirs->id, $ids);
        $this->assertNotContains($this->dioceseLine->id, $ids);
    }

    public function test_the_diocese_list_shows_shared_lines_only(): void
    {
        $this->ownLine($this->myChurch, 'Choir Robes');
        Sanctum::actingAs($this->approver);

        $ids = collect($this->getJson('/api/budget-lines')->assertOk()->json('data'))->pluck('id');

        $this->assertEqualsCanonicalizing([$this->churchLine->id, $this->allLine->id, $this->dioceseLine->id], $ids->all());
    }

    public function test_a_pastor_adds_a_line_owned_by_their_church(): void
    {
        Sanctum::actingAs($this->pastor);

        // Same name as a shared line: the slug only has to be unique within the church.
        $this->postJson('/api/budget-lines', [
            'budget_category_id' => $this->category->id,
            'name' => 'Church Rent',
            'territory_scope' => 'all',
        ])->assertCreated()
            ->assertJsonPath('data.territory_scope', 'church')
            ->assertJsonPath('data.territory_type', 'church')
            ->assertJsonPath('data.territory_id', $this->myChurch->id)
            ->assertJsonPath('data.slug', 'church-rent');

        $this->postJson('/api/budget-lines', ['budget_category_id' => $this->category->id, 'name' => 'Church Rent'])
            ->assertCreated()->assertJsonPath('data.slug', 'church-rent-2');
    }

    public function test_a_pastor_edits_and_deletes_their_own_line(): void
    {
        $mine = $this->ownLine($this->myChurch, 'Choir Robes');
        Sanctum::actingAs($this->pastor);

        $this->putJson("/api/budget-lines/{$mine->id}", ['name' => 'Choir Uniforms', 'territory_scope' => 'all'])
            ->assertOk()->assertJsonPath('data.name', 'Choir Uniforms')->assertJsonPath('data.territory_scope', 'church');
        $this->deleteJson("/api/budget-lines/{$mine->id}")->assertOk();
        $this->assertSoftDeleted('budget_lines', ['id' => $mine->id]);
    }

    public function test_shared_and_other_churches_lines_are_locked_for_a_pastor(): void
    {
        $theirs = $this->ownLine($this->otherChurch, 'Their Bus');
        Sanctum::actingAs($this->pastor);

        $this->putJson("/api/budget-lines/{$this->churchLine->id}", ['name' => 'Mine now'])->assertForbidden();
        $this->deleteJson("/api/budget-lines/{$this->allLine->id}")->assertForbidden();
        $this->putJson("/api/budget-lines/{$theirs->id}", ['name' => 'Mine now'])->assertForbidden();
        $this->deleteJson("/api/budget-lines/{$theirs->id}")->assertForbidden();
        $this->getJson("/api/budget-lines/{$theirs->id}")->assertNotFound();
    }

    public function test_a_church_sees_how_often_its_own_budgets_use_each_line(): void
    {
        $this->budgetFor($this->myChurch, 'draft', [$this->churchLine]);
        $this->budgetFor($this->otherChurch, 'draft', [$this->churchLine]);
        $this->budgetFor($this->otherChurch, 'draft', [$this->churchLine]);
        Sanctum::actingAs($this->pastor);

        $line = collect($this->getJson('/api/budget-lines')->json('data'))->firstWhere('id', $this->churchLine->id);
        $this->assertSame(1, $line['budget_line_items_count']);
    }

    public function test_a_line_used_in_a_budget_cannot_be_deleted(): void
    {
        $mine = $this->ownLine($this->myChurch, 'Choir Robes');
        $this->budgetFor($this->myChurch, 'draft', [$mine]);
        Sanctum::actingAs($this->pastor);

        $this->deleteJson("/api/budget-lines/{$mine->id}")
            ->assertStatus(422)->assertJsonPath('data.usage_count', 1);
        $this->assertNotSoftDeleted('budget_lines', ['id' => $mine->id]);
    }
}
