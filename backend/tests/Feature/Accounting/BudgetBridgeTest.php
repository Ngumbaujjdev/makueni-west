<?php

namespace Tests\Feature\Accounting;

use App\Models\BudgetEntry;
use App\Models\Journal;
use App\Services\Accounting\Ledger;
use App\Services\Budgets\BudgetBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One set of books for Budgets and Accounting (docs/specs/accounting-spec.md):
 * every budget entry has its journal, and money written in Accounting on a
 * budget line shows in the budget In use.
 */
class BudgetBridgeTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private \App\Models\Budget $budget;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        $this->budget = $this->budgetFor($this->myChurch, 'active', [$this->churchLine, $this->incomeLine], now()->year, 1);
    }

    private function record(array $over = []): BudgetEntry
    {
        return app(BudgetBook::class)->record($this->pastor, $this->budget, array_replace([
            'budget_line_id' => $this->incomeLine->id, 'amount' => 700, 'entry_date' => $this->day(1, 7), 'description' => 'Tithes', 'method' => 'mpesa',
        ], $over));
    }

    public function test_recording_in_budgets_posts_to_the_books_and_changes_follow(): void
    {
        $ledger = app(Ledger::class);
        $entry = $this->record();
        $journal = Journal::find($entry->journal_id);
        $this->assertSame('receipt', $journal->doc_type);
        $this->assertSame('budget_entry', $journal->source_type);
        $mpesa = $this->chart->placeAccount($this->myChurch, 'mpesa');
        $this->assertSame('1150-01', $mpesa->code, 'M-Pesa account made on first use');
        $this->assertEquals(700, $ledger->balance($this->myChurch, $mpesa));
        $this->assertEquals(700, $ledger->balance($this->myChurch, $this->acc('4000')));

        $book = app(BudgetBook::class);
        $book->changeEntry($this->pastor, $entry->fresh(), ['amount' => 900]);
        $this->assertSame('reversed', $journal->fresh()->status);
        $this->assertEquals(900, $ledger->balance($this->myChurch, $mpesa));

        $book->removeEntry($this->pastor, $entry->fresh());
        $this->assertEquals(0, $ledger->balance($this->myChurch, $mpesa));
        $book->restoreEntry($this->pastor, BudgetEntry::withTrashed()->find($entry->id));
        $this->assertEquals(900, $ledger->balance($this->myChurch, $mpesa));

        $spend = $this->record(['budget_line_id' => $this->churchLine->id, 'amount' => 250, 'method' => null, 'description' => 'Rent']);
        $this->assertSame('payment', Journal::find($spend->journal_id)->doc_type);
        $this->assertEquals(-250, $ledger->balance($this->myChurch, $this->cash()));
        $this->assertTrue($ledger->trialBalance($this->myChurch)['balanced']);
    }

    public function test_a_receipt_on_a_budget_line_shows_in_the_budget_and_is_changed_only_in_accounting(): void
    {
        Sanctum::actingAs($this->treasurer);
        $id = $this->postJson('/api/accounting/receipts', ['date' => $this->day(1, 8), 'account_id' => $this->cash()->id, 'party_name' => 'Members', 'lines' => [['account_id' => $this->acc('4000')->id, 'amount' => 1200]]])
            ->assertCreated()->json('data.id');
        $entry = BudgetEntry::where('journal_id', $id)->firstOrFail();
        $this->assertEquals(1200, $entry->amount);
        $this->assertSame('in', $entry->direction);
        $this->assertEquals(1200, (float) $this->budget->fresh()->total_income_actual);
        $this->assertSame(1, Journal::count(), 'Budgets did not post it a second time');

        try {
            app(BudgetBook::class)->changeEntry($this->pastor, $entry, ['amount' => 1]);
            $this->fail('Budgets changed an Accounting entry');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('reverse it there', collect($e->errors())->flatten()->first());
        }

        $this->postJson("/api/accounting/journals/{$id}/reverse", ['reason' => 'Wrong church'])->assertOk();
        $this->assertSoftDeleted('budget_entries', ['id' => $entry->id]);
        $this->assertEquals(0, (float) $this->budget->fresh()->total_income_actual);

        // A date with no budget In use: in the books only.
        $this->postJson('/api/accounting/receipts', ['date' => $this->day(3, 8), 'account_id' => $this->cash()->id, 'party_name' => 'Members', 'lines' => [['account_id' => $this->acc('4000')->id, 'amount' => 50]]])->assertCreated();
        $this->assertSame(1, BudgetEntry::withTrashed()->count());
    }

    public function test_the_backfill_posts_every_old_entry_once(): void
    {
        $a = $this->record();
        $b = $this->record(['budget_line_id' => $this->churchLine->id, 'amount' => 100, 'method' => 'cash']);
        // As they were before Accounting.
        DB::table('budget_entries')->update(['journal_id' => null]);
        DB::table('journal_lines')->delete();
        DB::table('journals')->delete();

        Artisan::call('accounting:backfill-budget-entries');
        $this->assertSame(2, Journal::count());
        $this->assertNotNull($a->fresh()->journal_id);
        $this->assertNotNull($b->fresh()->journal_id);
        Artisan::call('accounting:backfill-budget-entries');
        $this->assertSame(2, Journal::count(), 'safe to run again');
    }
}
