<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\BankReconciliation;
use App\Models\BankStatementLine;
use App\Models\JournalLine;
use App\Models\Territory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bank and M-Pesa reconciliation (docs/specs/accounting-spec.md, A2), the
 * standard statement:
 *
 *   balance per statement + deposits in transit - unpresented payments
 *   = balance per cashbook
 *
 * The treasurer ticks the book lines that are on the statement (or imports
 * the statement and lets them match), adds to the books what only the bank
 * knew (charges, interest, direct deposits), and submits when the
 * difference is nothing. Someone else signs it off.
 */
final class Reconciliations
{
    /** Days either side for a statement line to match a book line. */
    private const MATCH_DAYS = 7;

    /** Uncleared longer than this is flagged. */
    public const STALE_DAYS = 30;

    public function __construct(private Ledger $ledger, private Documents $docs, private BudgetBridge $bridge) {}

    /** Start one (or carry on with the one still open for this account). */
    public function start(Territory $place, User $user, array $data): BankReconciliation
    {
        $account = $this->bankAccount($place, (int) $data['account_id']);
        $this->docs->notFuture($data['statement_date']);
        $open = BankReconciliation::where('territory_id', $place->id)->where('account_id', $account->id)->whereIn('status', ['draft', 'returned', 'submitted'])->first();
        if ($open) {
            if ($open->status === 'submitted') {
                throw ValidationException::withMessages(['account_id' => ["The reconciliation of {$account->name} to ".$open->statement_date->format('j M Y').' is waiting for sign-off.']]);
            }
            $open->update(['statement_date' => $data['statement_date'], 'statement_balance' => round((float) $data['statement_balance'], 2)]);

            return $this->refresh($open);
        }
        $last = BankReconciliation::where('territory_id', $place->id)->where('account_id', $account->id)->where('status', 'approved')->max('statement_date');
        if ($last && $data['statement_date'] <= CarbonImmutable::parse($last)->toDateString()) {
            throw ValidationException::withMessages(['statement_date' => ['It was already reconciled to '.CarbonImmutable::parse($last)->format('j M Y').' - pick a later statement date.']]);
        }

        return $this->refresh(BankReconciliation::create([
            'territory_id' => $place->id,
            'account_id' => $account->id,
            'statement_date' => $data['statement_date'],
            'statement_balance' => round((float) $data['statement_balance'], 2),
            'status' => 'draft',
            'created_by' => $user->id,
        ]));
    }

    public function update(BankReconciliation $rec, array $data): BankReconciliation
    {
        $this->assertOpen($rec);
        if (isset($data['statement_date'])) {
            $this->docs->notFuture($data['statement_date']);
        }
        $rec->fill(array_intersect_key($data, array_flip(['statement_date', 'statement_balance', 'notes'])))->save();
        // Lines dated after a statement date that moved back can't stay ticked on it.
        JournalLine::where('reconciliation_id', $rec->id)->where('date', '>', $rec->statement_date->toDateString())->update(['cleared_on' => null, 'reconciliation_id' => null]);

        return $this->refresh($rec);
    }

    /** Tick (or untick) book lines as on the statement. */
    public function tick(BankReconciliation $rec, array $lineIds, bool $cleared): BankReconciliation
    {
        $this->assertOpen($rec);
        $lines = $this->bookLines($rec)->whereIn('id', $lineIds);
        if ($lines->count() !== count(array_unique($lineIds))) {
            throw ValidationException::withMessages(['line_ids' => ['Some of those lines aren\'t this account\'s, are after the statement date, or were cleared before.']]);
        }
        DB::transaction(function () use ($rec, $lines, $cleared) {
            foreach ($lines as $l) {
                $l->update($cleared ? ['cleared_on' => $rec->statement_date->toDateString(), 'reconciliation_id' => $rec->id] : ['cleared_on' => null, 'reconciliation_id' => null]);
                if (! $cleared) {
                    BankStatementLine::where('reconciliation_id', $rec->id)->where('matched_line_id', $l->id)->update(['matched_line_id' => null, 'status' => 'unmatched']);
                }
            }
        });

        return $this->refresh($rec);
    }

    /**
     * Put an imported statement on it (replacing any before), then match.
     *
     * @param  array<int, array{date: string, description?: ?string, reference?: ?string, money_in?: numeric, money_out?: numeric, balance?: ?numeric}>  $rows
     */
    public function import(BankReconciliation $rec, array $rows, ?array $mapping = null): BankReconciliation
    {
        $this->assertOpen($rec);
        if (count($rows) > 2000) {
            throw ValidationException::withMessages(['rows' => ['A statement of up to 2,000 lines can be imported at a time.']]);
        }
        DB::transaction(function () use ($rec, $rows, $mapping) {
            $old = BankStatementLine::where('reconciliation_id', $rec->id)->whereNotNull('matched_line_id')->where('status', 'matched')->pluck('matched_line_id');
            JournalLine::whereIn('id', $old)->where('reconciliation_id', $rec->id)->update(['cleared_on' => null, 'reconciliation_id' => null]);
            BankStatementLine::where('reconciliation_id', $rec->id)->where('status', '!=', 'added')->delete();
            foreach (array_values($rows) as $i => $r) {
                $in = round(abs((float) ($r['money_in'] ?? 0)), 2);
                $out = round(abs((float) ($r['money_out'] ?? 0)), 2);
                if ($in == 0 && $out == 0) {
                    continue;
                }
                BankStatementLine::create([
                    'reconciliation_id' => $rec->id,
                    'line_no' => $i + 1,
                    'date' => CarbonImmutable::parse($r['date'])->toDateString(),
                    'description' => isset($r['description']) ? mb_substr(trim((string) $r['description']), 0, 255) : null,
                    'reference' => isset($r['reference']) ? mb_substr(trim((string) $r['reference']), 0, 100) : null,
                    'money_in' => $in,
                    'money_out' => $out,
                    'balance' => isset($r['balance']) && $r['balance'] !== '' ? round((float) $r['balance'], 2) : null,
                    'status' => 'unmatched',
                ]);
            }
            if ($mapping) {
                DB::table('accounting_place_accounts')->updateOrInsert(
                    ['territory_id' => $rec->territory_id, 'account_id' => $rec->account_id],
                    ['statement_mapping' => json_encode($mapping), 'updated_at' => now(), 'created_at' => now()],
                );
            }
        });
        $this->autoMatch($rec);

        return $this->refresh($rec);
    }

    /**
     * Pair each unmatched statement line with one uncleared book line of the
     * same amount and direction within a week - a matching reference first,
     * then the closest date. One to one.
     */
    public function autoMatch(BankReconciliation $rec): int
    {
        $book = $this->bookLines($rec)->whereNull('reconciliation_id')->load('journal:id,number,reference')->values();
        $used = [];
        $n = 0;
        foreach (BankStatementLine::where('reconciliation_id', $rec->id)->where('status', 'unmatched')->orderBy('date')->get() as $s) {
            $in = (float) $s->money_in > 0;
            $amount = $in ? (float) $s->money_in : (float) $s->money_out;
            $text = strtoupper(($s->reference ?? '').' '.($s->description ?? ''));
            $candidates = $book->filter(fn ($l) => ! isset($used[$l->id])
                && abs(($in ? (float) $l->debit : (float) $l->credit) - $amount) < 0.005
                && abs($l->date->diffInDays($s->date)) <= self::MATCH_DAYS);
            if ($candidates->isEmpty()) {
                continue;
            }
            $best = $candidates->sortBy(function ($l) use ($text, $s) {
                $ref = strtoupper((string) ($l->journal->reference ?? ''));
                $hit = ($ref !== '' && str_contains($text, $ref)) || str_contains($text, strtoupper($l->journal->number ?? '~'));

                return ($hit ? 0 : 1000) + abs($l->date->diffInDays($s->date));
            })->first();
            $used[$best->id] = true;
            $this->pair($rec, $s, $best);
            $n++;
        }

        return $n;
    }

    /** Pair a statement line with a book line by hand. */
    public function match(BankReconciliation $rec, BankStatementLine $s, int $lineId): BankReconciliation
    {
        $this->assertOpen($rec);
        $line = $this->bookLines($rec)->firstWhere('id', $lineId);
        $in = (float) $s->money_in > 0;
        if (! $line || $line->reconciliation_id) {
            throw ValidationException::withMessages(['line_id' => ['That book line isn\'t free to match.']]);
        }
        if (abs(($in ? (float) $line->debit : (float) $line->credit) - ($in ? (float) $s->money_in : (float) $s->money_out)) >= 0.005) {
            throw ValidationException::withMessages(['line_id' => ['The amounts are different.']]);
        }
        $this->pair($rec, $s, $line);

        return $this->refresh($rec);
    }

    public function unmatch(BankReconciliation $rec, BankStatementLine $s): BankReconciliation
    {
        $this->assertOpen($rec);
        if ($s->matched_line_id) {
            JournalLine::whereKey($s->matched_line_id)->where('reconciliation_id', $rec->id)->update(['cleared_on' => null, 'reconciliation_id' => null]);
        }
        $s->update(['matched_line_id' => null, 'status' => 'unmatched']);

        return $this->refresh($rec);
    }

    public function ignore(BankReconciliation $rec, BankStatementLine $s, bool $ignore = true): BankReconciliation
    {
        $this->assertOpen($rec);
        if ($s->status === 'matched' || $s->status === 'added') {
            throw ValidationException::withMessages(['line' => ['Unmatch it first.']]);
        }
        $s->update(['status' => $ignore ? 'ignored' : 'unmatched']);

        return $this->refresh($rec);
    }

    /**
     * What only the bank knew - charges, interest, a direct deposit - into the
     * books from the statement line, already matched and cleared.
     */
    public function addToBooks(BankReconciliation $rec, BankStatementLine $s, User $user, array $data): BankReconciliation
    {
        $this->assertOpen($rec);
        if ($s->status !== 'unmatched') {
            throw ValidationException::withMessages(['line' => ['Only an unmatched statement line can be added to the books.']]);
        }
        $place = Territory::findOrFail($rec->territory_id);
        $bank = AccountingAccount::findOrFail($rec->account_id);
        $other = $this->docs->postable($place, (int) $data['account_id'], 'account_id');
        if ($other->isCash()) {
            throw ValidationException::withMessages(['account_id' => ['Money between your own accounts is a transfer - record it with Move money.']]);
        }
        $in = (float) $s->money_in > 0;
        $amount = $in ? (float) $s->money_in : (float) $s->money_out;

        return DB::transaction(function () use ($rec, $s, $user, $place, $bank, $other, $in, $amount, $data) {
            $budgetLine = $this->docs->budgetLine($place, $other, $data['budget_line_id'] ?? null, 'budget_line_id');
            $journal = $this->ledger->post($place, [
                'doc_type' => $in ? 'receipt' : 'payment',
                'date' => $s->date->toDateString(),
                'narration' => trim(($data['narration'] ?? '') ?: ($s->description ?: 'From the statement')),
                'party_name' => $in ? ($data['party_name'] ?? null) : ($bank->bank_name ?: $bank->name),
                'method' => $bank->cash_kind === 'mpesa' ? 'mpesa' : 'bank',
                'reference' => $s->reference,
                'source_type' => 'bank_statement',
                'source_id' => $s->id,
            ], [
                ['account_id' => $in ? $bank->id : $other->id, 'debit' => $amount, 'budget_line_id' => $in ? null : $budgetLine, 'fund_id' => $in ? null : $this->docs->fund($data['fund_id'] ?? null, 'fund_id')],
                ['account_id' => $in ? $other->id : $bank->id, 'credit' => $amount, 'budget_line_id' => $in ? $budgetLine : null, 'fund_id' => $in ? $this->docs->fund($data['fund_id'] ?? null, 'fund_id') : null],
            ], $user);
            $this->bridge->journalPosted($journal, $user);
            $bankLine = $journal->lines->firstWhere('account_id', $bank->id);
            $bankLine->update(['cleared_on' => $rec->statement_date->toDateString(), 'reconciliation_id' => $rec->id]);
            $s->update(['matched_line_id' => $bankLine->id, 'status' => 'added']);

            return $this->refresh($rec);
        });
    }

    public function submit(BankReconciliation $rec, User $user): BankReconciliation
    {
        $this->assertOpen($rec);
        $rec = $this->refresh($rec);
        if (abs((float) $rec->difference) >= 0.005) {
            throw ValidationException::withMessages(['difference' => ['It doesn\'t agree yet - a difference of KES '.number_format(abs((float) $rec->difference), 2).' is left.']]);
        }
        $rec->update(['status' => 'submitted', 'prepared_by' => $user->id, 'prepared_at' => now(), 'return_reason' => null]);

        return $rec->fresh();
    }

    public function approve(BankReconciliation $rec, User $user): BankReconciliation
    {
        if ($rec->status !== 'submitted') {
            throw ValidationException::withMessages(['status' => ['Only a reconciliation waiting for sign-off can be signed off.']]);
        }
        if ((int) $rec->prepared_by === (int) $user->id) {
            throw ValidationException::withMessages(['reconciliation' => ['You prepared this reconciliation - someone else must sign it off.']]);
        }
        $figures = $this->figures($rec);
        if (abs($figures['difference']) >= 0.005) {
            throw ValidationException::withMessages(['difference' => ['The books changed since it was submitted - it no longer agrees. Send it back.']]);
        }
        $rec->update($figures + ['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()]);

        return $rec->fresh();
    }

    public function sendBack(BankReconciliation $rec, User $user, string $reason): BankReconciliation
    {
        if ($rec->status !== 'submitted') {
            throw ValidationException::withMessages(['status' => ['Only a reconciliation waiting for sign-off can be sent back.']]);
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Say what needs looking at.']]);
        }
        $rec->update(['status' => 'returned', 'return_reason' => mb_substr($reason, 0, 255)]);

        return $rec->fresh();
    }

    /** Throw away a reconciliation still being prepared: its ticks come off. */
    public function discard(BankReconciliation $rec): void
    {
        $this->assertOpen($rec);
        if (BankStatementLine::where('reconciliation_id', $rec->id)->where('status', 'added')->exists()) {
            throw ValidationException::withMessages(['reconciliation' => ['Lines were added to the books from this statement - finish it instead.']]);
        }
        DB::transaction(function () use ($rec) {
            JournalLine::where('reconciliation_id', $rec->id)->update(['cleared_on' => null, 'reconciliation_id' => null]);
            $rec->delete();
        });
    }

    /**
     * The book lines of this account up to the statement date that aren't
     * cleared by an earlier reconciliation (ticked here or not yet).
     */
    public function bookLines(BankReconciliation $rec): Collection
    {
        return JournalLine::where('territory_id', $rec->territory_id)->where('account_id', $rec->account_id)
            ->where('date', '<=', $rec->statement_date->toDateString())
            ->where(fn ($q) => $q->whereNull('reconciliation_id')->orWhere('reconciliation_id', $rec->id))
            ->orderBy('date')->orderBy('id')->get();
    }

    /** The statement worked out: book balance, in transit, unpresented, the difference. */
    public function figures(BankReconciliation $rec): array
    {
        $place = Territory::findOrFail($rec->territory_id);
        $account = AccountingAccount::findOrFail($rec->account_id);
        $book = $this->ledger->balance($place, $account, $rec->statement_date->toDateString());
        $open = $this->bookLines($rec)->whereNull('reconciliation_id');
        $inTransit = round($open->sum(fn ($l) => (float) $l->debit), 2);
        $unpresented = round($open->sum(fn ($l) => (float) $l->credit), 2);

        return [
            'book_balance' => $book,
            'in_transit' => $inTransit,
            'unpresented' => $unpresented,
            'difference' => round((float) $rec->statement_balance + $inTransit - $unpresented - $book, 2),
        ];
    }

    private function refresh(BankReconciliation $rec): BankReconciliation
    {
        if ($rec->isOpen()) {
            $rec->update($this->figures($rec));
        }

        return $rec->fresh();
    }

    private function pair(BankReconciliation $rec, BankStatementLine $s, JournalLine $l): void
    {
        $l->update(['cleared_on' => $s->date->toDateString(), 'reconciliation_id' => $rec->id]);
        $s->update(['matched_line_id' => $l->id, 'status' => 'matched']);
    }

    private function bankAccount(Territory $place, int $id): AccountingAccount
    {
        $a = $this->docs->cashAccount($place, $id, 'account_id');
        if (! in_array($a->cash_kind, ['bank', 'mpesa'], true)) {
            throw ValidationException::withMessages(['account_id' => ['Reconcile a bank or M-Pesa account. Cash is counted instead.']]);
        }

        return $a;
    }

    private function assertOpen(BankReconciliation $rec): void
    {
        if (! $rec->isOpen()) {
            throw ValidationException::withMessages(['status' => [$rec->status === 'approved' ? 'This reconciliation is signed off.' : 'It is waiting for sign-off - it can be changed if it is sent back.']]);
        }
    }

    /** For the page: the reconciliation with its book lines and statement lines. */
    public function present(BankReconciliation $rec): array
    {
        $rec->loadMissing(['account', 'preparer', 'approver']);
        $book = $rec->isOpen() ? $this->bookLines($rec) : JournalLine::where('reconciliation_id', $rec->id)->orderBy('date')->get();
        $book->load(['journal:id,number,doc_type,party_name,narration,reference,status']);
        $others = JournalLine::with('account:id,name')->whereIn('journal_id', $book->pluck('journal_id')->unique())->where('account_id', '!=', $rec->account_id)->get()->groupBy('journal_id');
        $today = CarbonImmutable::today();
        $figures = $rec->isOpen() ? $this->figures($rec) : $rec->only(['book_balance', 'in_transit', 'unpresented', 'difference']);

        return [
            'id' => $rec->id,
            'status' => $rec->status,
            'status_label' => BankReconciliation::STATUSES[$rec->status],
            'account' => ['id' => $rec->account->id, 'name' => $rec->account->name, 'kind' => $rec->account->cash_kind, 'code' => $rec->account->code, 'number_masked' => $rec->account->maskedNumber()],
            'statement_date' => $rec->statement_date->toDateString(),
            'statement_balance' => (float) $rec->statement_balance,
            'book_balance' => (float) $figures['book_balance'],
            'in_transit' => (float) $figures['in_transit'],
            'unpresented' => (float) $figures['unpresented'],
            'difference' => (float) $figures['difference'],
            'notes' => $rec->notes,
            'prepared_by' => $rec->preparer?->full_name,
            'prepared_by_id' => $rec->prepared_by,
            'prepared_at' => $rec->prepared_at?->toIso8601String(),
            'approved_by' => $rec->approver?->full_name,
            'approved_at' => $rec->approved_at?->toIso8601String(),
            'return_reason' => $rec->return_reason,
            'book' => $book->map(fn ($l) => [
                'id' => $l->id,
                'date' => $l->date->toDateString(),
                'number' => $l->journal->number,
                'doc_type' => $l->journal->doc_type,
                'party' => $l->journal->party_name,
                'details' => $l->journal->narration,
                'reference' => $l->journal->reference,
                'against' => ($others[$l->journal_id] ?? collect())->map(fn ($o) => $o->account?->name)->unique()->values()->all(),
                'in' => (float) $l->debit,
                'out' => (float) $l->credit,
                'cleared' => (int) $l->reconciliation_id === (int) $rec->id,
                'stale' => ! $l->reconciliation_id && $l->date->diffInDays($today) > self::STALE_DAYS,
            ])->values()->all(),
            'statement' => $rec->statementLines()->get()->map(fn ($s) => [
                'id' => $s->id,
                'date' => $s->date->toDateString(),
                'description' => $s->description,
                'reference' => $s->reference,
                'in' => (float) $s->money_in,
                'out' => (float) $s->money_out,
                'balance' => $s->balance !== null ? (float) $s->balance : null,
                'status' => $s->status,
                'matched_line_id' => $s->matched_line_id,
            ])->values()->all(),
            'mapping' => DB::table('accounting_place_accounts')->where('territory_id', $rec->territory_id)->where('account_id', $rec->account_id)->value('statement_mapping'),
        ];
    }
}
