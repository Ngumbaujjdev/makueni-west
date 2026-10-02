<?php

namespace Tests\Feature\Financial;

use App\Models\BudgetEntry;
use App\Services\Budgets\BudgetBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Receipts on entries (docs/specs/budgets-spec.md → Receipts): a photo or
 * PDF on an entry, kept private, streamed only to people who may see the
 * budget; only the place itself adds or removes them.
 */
class BudgetReceiptsTest extends TestCase
{
    use BuildsBudgetWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
        Storage::fake('local');
    }

    private function entryOf($church): BudgetEntry
    {
        $budget = $this->budgetFor($church, 'active', [$this->churchLine]);

        return app(BudgetBook::class)->record($this->pastor, $budget, ['budget_line_id' => $this->churchLine->id, 'amount' => 500, 'entry_date' => '2026-01-10', 'description' => 'Rent']);
    }

    private function attach(BudgetEntry $entry, ?UploadedFile $file = null)
    {
        return $this->post("/api/budget-entries/{$entry->id}/receipts", ['receipt' => $file ?? UploadedFile::fake()->image('receipt.jpg')], ['Accept' => 'application/json']);
    }

    public function test_a_receipt_is_attached_streamed_listed_and_removed(): void
    {
        $entry = $this->entryOf($this->myChurch);
        Sanctum::actingAs($this->pastor);

        $rows = $this->attach($entry)->assertCreated()->json('data');
        $this->assertSame('image', $rows[0]['type']);
        $this->attach($entry, UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n"))->assertCreated();

        $show = $this->getJson("/api/budget-entries/{$entry->id}")->assertOk()->json('data');
        $this->assertCount(2, $show['receipts']);
        $this->assertTrue($show['can']['receipts']);
        $this->assertContains('Attached a receipt to Rent', collect($show['history'])->pluck('description'));
        $this->assertSame(2, collect($this->getJson('/api/budget-entries?year=2026&month=1')->json('data'))->firstWhere('id', $entry->id)['receipts']);

        $this->get("/api/budget-entries/{$entry->id}/receipts/{$rows[0]['id']}")->assertOk();

        $this->deleteJson("/api/budget-entries/{$entry->id}/receipts/{$rows[0]['id']}")->assertOk();
        $this->assertCount(1, $entry->fresh()->getMedia('receipts'));
    }

    public function test_a_level_above_can_view_but_not_add_and_another_place_cannot_open_it(): void
    {
        $entry = $this->entryOf($this->myChurch);
        Sanctum::actingAs($this->pastor);
        $id = $this->attach($entry)->json('data.0.id');

        Sanctum::actingAs($this->overseer);
        $this->get("/api/budget-entries/{$entry->id}/receipts/{$id}")->assertOk();
        $this->assertFalse($this->getJson("/api/budget-entries/{$entry->id}")->json('data.can.receipts'));
        $this->attach($entry)->assertForbidden();
        $this->deleteJson("/api/budget-entries/{$entry->id}/receipts/{$id}")->assertForbidden();

        $far = $this->entryOf($this->farChurch);
        Sanctum::actingAs($this->pastor);
        $this->attach($far)->assertForbidden();
        $this->getJson("/api/budget-entries/{$far->id}/receipts/{$id}")->assertForbidden();
    }

    public function test_wrong_type_too_big_or_too_many_is_refused(): void
    {
        $entry = $this->entryOf($this->myChurch);
        Sanctum::actingAs($this->pastor);

        $this->attach($entry, UploadedFile::fake()->create('notes.docx', 10))->assertStatus(422);
        $this->attach($entry, UploadedFile::fake()->image('big.jpg')->size(6000))->assertStatus(422);
        foreach (range(1, BudgetEntry::MAX_RECEIPTS) as $i) {
            $this->attach($entry)->assertCreated();
        }
        $this->attach($entry)->assertStatus(422);
    }
}
