<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\CashCount;
use App\Models\Territory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Counting the cash against the book (docs/specs/accounting-spec.md, A2).
 * The custodian counts the notes and coins; when it agrees with the cashbook
 * it is balanced and that's all. When it doesn't, they say why and a second
 * person approves it - then the shortage or overage is posted to 5950, so
 * the book matches the cash and the trail shows why.
 */
final class CashCounts
{
    public function __construct(private Chart $chart, private Ledger $ledger, private Documents $docs) {}

    /** @param array{account_id: int, counted_on: string, denominations?: ?array, counted_total?: numeric, reason?: ?string, is_surprise?: bool} $data */
    public function count(Territory $place, User $user, array $data): CashCount
    {
        $account = $this->docs->cashAccount($place, (int) $data['account_id'], 'account_id');
        if (! in_array($account->cash_kind, ['cash', 'petty_cash'], true)) {
            throw ValidationException::withMessages(['account_id' => ['Count cash at hand or petty cash. A bank or M-Pesa account is reconciled against its statement.']]);
        }
        $this->docs->notFuture($data['counted_on']);
        if (CashCount::where('territory_id', $place->id)->where('account_id', $account->id)->where('status', 'waiting')->exists()) {
            throw ValidationException::withMessages(['account_id' => ["A count of {$account->name} is waiting for approval - approve or send it back first."]]);
        }
        [$denominations, $counted] = $this->total($data);
        $book = $this->ledger->balance($place, $account, $data['counted_on']);
        $difference = round($counted - $book, 2);
        $balanced = abs($difference) < 0.005;
        $reason = trim((string) ($data['reason'] ?? ''));
        if (! $balanced && $reason === '') {
            throw ValidationException::withMessages(['reason' => ['The count is '.($difference < 0 ? 'short' : 'over').' by KES '.number_format(abs($difference), 2).' - say why.']]);
        }

        return CashCount::create([
            'territory_id' => $place->id,
            'account_id' => $account->id,
            'counted_on' => $data['counted_on'],
            'denominations' => $denominations,
            'counted_total' => $counted,
            'book_balance' => $book,
            'difference' => $balanced ? 0 : $difference,
            'reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
            'is_surprise' => (bool) ($data['is_surprise'] ?? false),
            'status' => $balanced ? 'balanced' : 'waiting',
            'counted_by' => $user->id,
        ]);
    }

    /** A second person accepts the difference: post it to Cash shortage / over. */
    public function approve(CashCount $count, User $user): CashCount
    {
        $this->assertWaiting($count);
        if ((int) $count->counted_by === (int) $user->id) {
            throw ValidationException::withMessages(['count' => ['You counted this cash - someone else must approve the difference.']]);
        }
        $place = Territory::findOrFail($count->territory_id);
        $cash = AccountingAccount::findOrFail($count->account_id);
        $diff = (float) $count->difference;
        $shortOver = $this->chart->account('cash_short_over');

        return DB::transaction(function () use ($count, $user, $place, $cash, $diff, $shortOver) {
            $journal = $this->ledger->post($place, [
                'doc_type' => 'journal',
                'date' => $count->counted_on->toDateString(),
                'narration' => "Cash count of {$cash->name} on {$count->counted_on->format('j M Y')}: ".($diff < 0 ? 'short' : 'over')." - {$count->reason}",
                'source_type' => 'cash_count',
                'source_id' => $count->id,
            ], $diff < 0
                ? [['account_id' => $shortOver->id, 'debit' => -$diff, 'memo' => 'Cash short'], ['account_id' => $cash->id, 'credit' => -$diff]]
                : [['account_id' => $cash->id, 'debit' => $diff], ['account_id' => $shortOver->id, 'credit' => $diff, 'memo' => 'Cash over']], $user, true);
            $count->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'journal_id' => $journal->id]);

            return $count->fresh();
        });
    }

    /** Send it back: count again. */
    public function reject(CashCount $count, User $user, string $reason): CashCount
    {
        $this->assertWaiting($count);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Say why it should be counted again.']]);
        }
        $count->update(['status' => 'rejected', 'approved_by' => $user->id, 'approved_at' => now(), 'reject_reason' => mb_substr($reason, 0, 255)]);

        return $count->fresh();
    }

    /** The last count of each money account at a place (account_id => count). */
    public function latest(Territory $place): array
    {
        return CashCount::where('territory_id', $place->id)->whereIn('status', ['balanced', 'approved', 'waiting'])
            ->orderByDesc('counted_on')->orderByDesc('id')->get()->unique('account_id')->keyBy('account_id')->all();
    }

    /** The notes and coins added up, or the total typed in. */
    private function total(array $data): array
    {
        $den = [];
        $sum = 0;
        foreach (CashCount::DENOMINATIONS as $d) {
            $n = (int) ($data['denominations'][$d] ?? 0);
            if ($n < 0) {
                throw ValidationException::withMessages(['denominations' => ['A count can\'t be less than nothing.']]);
            }
            if ($n > 0) {
                $den[$d] = $n;
                $sum += $n * (int) $d;
            }
        }
        if ($den) {
            return [$den, round($sum, 2)];
        }
        if (! isset($data['counted_total']) || $data['counted_total'] === '' || (float) $data['counted_total'] < 0) {
            throw ValidationException::withMessages(['counted_total' => ['Count the notes and coins, or enter the total counted.']]);
        }

        return [null, round((float) $data['counted_total'], 2)];
    }

    private function assertWaiting(CashCount $count): void
    {
        if ($count->status !== 'waiting') {
            throw ValidationException::withMessages(['count' => ['Only a count with a difference waiting for approval can be approved or sent back.']]);
        }
    }

    /** First day of a month, last day - for the month-end checks. */
    public static function monthBounds(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1);

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }
}
