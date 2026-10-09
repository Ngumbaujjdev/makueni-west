<?php

namespace App\Services\Accounting;

use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\BudgetEntry;
use App\Models\BudgetLine;
use App\Models\Journal;
use App\Models\Territory;
use App\Models\User;
use App\Services\Budgets\BudgetBook;
use Illuminate\Validation\ValidationException;

/**
 * One set of books for Budgets and Accounting (docs/specs/accounting-spec.md).
 *
 * Budgets -> Accounting: every budget entry has a journal. Recording one
 * posts a receipt (money in) or payment (money out) between the place's
 * cash, bank or M-Pesa (from the entry's method) and its line's account;
 * changing it reverses that journal and posts a new one; removing it
 * reverses; bringing it back posts again.
 *
 * Accounting -> Budgets: a receipt or payment line on a budget line, dated
 * in a budget In use, adds a budget entry tied to the journal, so the
 * budget's actuals follow the books. Such an entry is changed only by
 * reversing the document in Accounting.
 */
final class BudgetBridge
{
    private const SOURCE = 'budget_entry';

    public function __construct(private Chart $chart, private Ledger $ledger) {}

    /** Is this entry one Accounting made (so it's changed there, not in Budgets)? */
    public function fromAccounting(BudgetEntry $entry): ?Journal
    {
        if (! $entry->journal_id) {
            return null;
        }
        $journal = Journal::find($entry->journal_id);

        return $journal && $journal->source_type !== self::SOURCE ? $journal : null;
    }

    /** Refuse a change in Budgets to an entry Accounting made. */
    public function assertChangeable(BudgetEntry $entry): void
    {
        if ($journal = $this->fromAccounting($entry)) {
            $what = strtolower(Journal::TYPES[$journal->doc_type] ?? 'document');
            throw ValidationException::withMessages(['entry' => ["This came from {$what} {$journal->number} in Accounting - reverse it there to change it."]]);
        }
    }

    /** A budget entry was recorded (or brought back): post its journal. */
    public function entryRecorded(BudgetEntry $entry, ?User $user): Journal
    {
        $budget = $entry->budget ?? Budget::findOrFail($entry->budget_id);
        $place = Territory::findOrFail($budget->territory_id);
        $line = $entry->lineItem?->budgetLine ?? BudgetLine::withTrashed()->findOrFail($entry->lineItem->budget_line_id);
        $cash = $this->chart->placeAccount($place, $this->kindFor($entry->method), $user?->id);
        $account = $this->chart->forBudgetLine($line);
        $amount = (float) $entry->amount;
        $in = $entry->direction === 'in';

        $journal = $this->ledger->post($place, [
            'doc_type' => $in ? 'receipt' : 'payment',
            'date' => $entry->entry_date->toDateString(),
            'narration' => $entry->description,
            'party_name' => $entry->counterparty,
            'method' => $entry->method,
            'reference' => $entry->reference,
            'source_type' => self::SOURCE,
            'source_id' => $entry->id,
        ], [
            ['account_id' => $in ? $cash->id : $account->id, 'debit' => $amount, 'budget_line_id' => $in ? null : $line->id, 'memo' => $in ? null : $line->name],
            ['account_id' => $in ? $account->id : $cash->id, 'credit' => $amount, 'budget_line_id' => $in ? $line->id : null, 'memo' => $in ? $line->name : null],
        ], $user, true);
        $entry->forceFill(['journal_id' => $journal->id])->saveQuietly();

        return $journal;
    }

    /** A budget entry was changed: reverse its journal and post it again as it is now. */
    public function entryChanged(BudgetEntry $entry, ?User $user): void
    {
        $this->reverseOwn($entry, $user, 'Changed in Budgets');
        $this->entryRecorded($entry->fresh(['budget', 'lineItem.budgetLine']), $user);
    }

    /** A budget entry was removed: reverse its journal. */
    public function entryRemoved(BudgetEntry $entry, ?User $user): void
    {
        $this->reverseOwn($entry, $user, 'Removed in Budgets');
    }

    /** A document posted in Accounting: add a budget entry for each line on a budget line, when a budget is In use that day. */
    public function journalPosted(Journal $journal, ?User $user): void
    {
        if (! in_array($journal->doc_type, ['receipt', 'payment'], true) || $journal->source_type === self::SOURCE) {
            return;
        }
        $place = Territory::find($journal->territory_id);
        $budget = $place ? app(BudgetBook::class)->budgetInUseOn($place->territory_type->value, $place->id, $journal->date->toDateString()) : null;
        if (! $budget) {
            return;
        }
        $book = app(BudgetBook::class);
        $categories = BudgetCategory::pluck('slug', 'id');
        foreach ($journal->lines()->with('account')->get() as $l) {
            if (! $l->budget_line_id) {
                continue;
            }
            $line = BudgetLine::find($l->budget_line_id);
            $slug = $line ? ($categories[$line->budget_category_id] ?? null) : null;
            // Income lines take money in on the credit side, expense lines pay out on the debit side.
            $amount = $slug === 'income' ? (float) $l->credit - (float) $l->debit : ($slug === 'expense' ? (float) $l->debit - (float) $l->credit : 0);
            if ($amount <= 0) {
                continue;
            }
            try {
                $book->record($user, $budget, [
                    'budget_line_id' => $line->id,
                    'amount' => $amount,
                    'entry_date' => $journal->date->toDateString(),
                    'description' => mb_substr($l->memo ?: ($journal->narration ?: (Journal::TYPES[$journal->doc_type].' '.$journal->number)), 0, 255),
                    'counterparty' => $journal->party_name,
                    'method' => $journal->method,
                    'reference' => $journal->reference ?: $journal->number,
                    'journal_id' => $journal->id,
                ]);
            } catch (ValidationException) {
                // The budget can't take this line (a line it may not use) - the books still have it.
            }
        }
    }

    /** A document was reversed in Accounting: take its budget entries off. */
    public function journalReversed(Journal $journal, ?User $user): void
    {
        if ($journal->source_type === self::SOURCE) {
            return;
        }
        $book = app(BudgetBook::class);
        foreach (BudgetEntry::where('journal_id', $journal->id)->get() as $entry) {
            $book->removeEntry($user, $entry, true);
        }
    }

    /** cash / mpesa / bank / cheque -> which kind of money account. */
    private function kindFor(?string $method): string
    {
        return match ($method) {
            'mpesa' => 'mpesa',
            'bank', 'cheque' => 'bank',
            default => 'cash',
        };
    }

    private function reverseOwn(BudgetEntry $entry, ?User $user, string $reason): void
    {
        $journal = $entry->journal_id ? Journal::find($entry->journal_id) : null;
        if ($journal && $journal->source_type === self::SOURCE && $journal->status === 'posted') {
            $this->ledger->reverse($journal, $user, $reason, max(now()->toDateString(), $journal->date->toDateString()));
        }
    }
}
