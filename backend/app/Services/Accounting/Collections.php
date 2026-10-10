<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\ChurchAttendanceRecord;
use App\Models\Collection;
use App\Models\Journal;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sunday collections (docs/specs/accounting-spec.md, A3) - where most church
 * money starts. One person records the count (cash and M-Pesa for each kind
 * of giving, optionally the notes and coins); a second person confirms it,
 * which posts one official receipt - Dr cash / Dr M-Pesa, Cr each kind with
 * its fund - or sends it back. Then the cash is banked with a transfer.
 */
final class Collections
{
    /** The kinds offered first: label => [account code, fund code]. */
    public const PRESETS = [
        'Offering' => ['4010', 'GEN'],
        'Tithe' => ['4000', 'GEN'],
        'Thanksgiving' => ['4020', 'GEN'],
        'Building' => ['4020', 'BLD'],
        'KYS' => ['4020', 'KYS'],
    ];

    public function __construct(private Chart $chart, private Ledger $ledger, private Documents $docs, private BudgetBridge $bridge) {}

    /** @param array{date: string, title?: ?string, attendance_record_id?: ?int, cash_account_id?: ?int, mpesa_account_id?: ?int, denominations?: ?array, witnesses?: ?array, notes?: ?string, lines: array} $data */
    public function record(Territory $place, User $user, array $data): Collection
    {
        $fields = $this->check($place, $data, $user);

        return DB::transaction(function () use ($place, $user, $fields) {
            $c = Collection::create($fields['collection'] + ['territory_id' => $place->id, 'status' => 'counted', 'counted_by' => $user->id]);
            $c->lines()->createMany($fields['lines']);

            return $c->load('lines');
        });
    }

    /** Change it while it waits, or after it was sent back (it waits again). */
    public function update(Collection $c, User $user, array $data): Collection
    {
        $this->assertStatus($c, ['counted', 'returned'], 'Only a collection waiting to be confirmed, or sent back, can be changed.');
        $place = Territory::findOrFail($c->territory_id);
        $fields = $this->check($place, $data, $user);

        return DB::transaction(function () use ($c, $user, $fields) {
            $c->update($fields['collection'] + ['status' => 'counted', 'counted_by' => $user->id, 'return_reason' => null]);
            $c->lines()->delete();
            $c->lines()->createMany($fields['lines']);

            return $c->fresh('lines');
        });
    }

    /** The second person confirms: one official receipt goes into the books. */
    public function confirm(Collection $c, User $user): Collection
    {
        $this->assertStatus($c, ['counted'], 'Only a collection waiting to be confirmed can be confirmed.');
        if ((int) $c->counted_by === (int) $user->id) {
            throw ValidationException::withMessages(['collection' => ['You counted this collection - a second person must confirm it.']]);
        }
        $place = Territory::findOrFail($c->territory_id);

        return DB::transaction(function () use ($c, $user, $place) {
            $c = Collection::with('lines')->whereKey($c->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($c, ['counted'], 'Only a collection waiting to be confirmed can be confirmed.');
            $lines = [];
            if ((float) $c->cash_total > 0) {
                $lines[] = ['account_id' => $c->cash_account_id, 'debit' => $c->cash_total, 'memo' => 'Cash'];
            }
            if ((float) $c->mpesa_total > 0) {
                $lines[] = ['account_id' => $c->mpesa_account_id, 'debit' => $c->mpesa_total, 'memo' => 'M-Pesa'];
            }
            foreach ($c->lines as $l) {
                $account = AccountingAccount::findOrFail($l->account_id);
                $lines[] = [
                    'account_id' => $l->account_id,
                    'credit' => round((float) $l->cash_amount + (float) $l->mpesa_amount, 2),
                    'fund_id' => $l->fund_id,
                    'budget_line_id' => $this->docs->budgetLine($place, $account, null, 'lines'),
                    'memo' => $l->label,
                ];
            }
            $journal = $this->ledger->post($place, [
                'doc_type' => 'receipt',
                'date' => $c->date->toDateString(),
                'narration' => "{$c->title} - ".$c->date->format('j M Y'),
                'party_name' => $c->title,
                'method' => (float) $c->cash_total > 0 ? 'cash' : 'mpesa',
                'source_type' => 'collection',
                'source_id' => $c->id,
            ], $lines, $user);
            $this->bridge->journalPosted($journal, $user);
            $c->update(['status' => 'posted', 'confirmed_by' => $user->id, 'confirmed_at' => now(), 'journal_id' => $journal->id]);

            return $c->fresh('lines');
        });
    }

    public function sendBack(Collection $c, User $user, string $reason): Collection
    {
        $this->assertStatus($c, ['counted'], 'Only a collection waiting to be confirmed can be sent back.');
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Say what needs checking.']]);
        }
        $c->update(['status' => 'returned', 'return_reason' => mb_substr($reason, 0, 255)]);

        return $c->fresh('lines');
    }

    /** Throw away a count that was never confirmed. */
    public function delete(Collection $c): void
    {
        $this->assertStatus($c, ['counted', 'returned'], 'A confirmed collection is in the books - reverse it instead.');
        $c->delete();
    }

    /** Bank the cash: a transfer from where it was kept to the bank, linked to the collection. */
    public function bank(Collection $c, User $user, array $data): Collection
    {
        $this->assertStatus($c, ['posted'], 'Confirm the collection before banking it.');
        if ($c->banking_journal_id) {
            throw ValidationException::withMessages(['collection' => ['This collection was banked already.']]);
        }
        if ((float) $c->cash_total <= 0) {
            throw ValidationException::withMessages(['collection' => ['There was no cash in this collection to bank.']]);
        }
        $place = Territory::findOrFail($c->territory_id);

        return DB::transaction(function () use ($c, $user, $data, $place) {
            $journal = $this->docs->transfer($place, $user, [
                'date' => $data['date'],
                'from_account_id' => $c->cash_account_id,
                'to_account_id' => (int) $data['to_account_id'],
                'amount' => $data['amount'] ?? $c->cash_total,
                'reference' => $data['reference'] ?? null,
                'narration' => "Banked: {$c->title} - ".$c->date->format('j M Y'),
            ]);
            $journal->update(['source_type' => 'collection_banking', 'source_id' => $c->id]);
            $c->update(['banking_journal_id' => $journal->id]);

            return $c->fresh('lines');
        });
    }

    /** Undo a confirmed collection: its receipt is reversed (and its banking, if not cleared). */
    public function reverse(Collection $c, User $user, string $reason): Collection
    {
        $this->assertStatus($c, ['posted'], 'Only a confirmed collection can be reversed.');
        if ($c->banking_journal_id) {
            throw ValidationException::withMessages(['collection' => ['It was banked - reverse the banking transfer first.']]);
        }

        return DB::transaction(function () use ($c, $user, $reason) {
            $journal = Journal::findOrFail($c->journal_id);
            $this->ledger->reverse($journal, $user, $reason);
            $this->bridge->journalReversed($journal, $user);
            $c->update(['status' => 'reversed']);

            return $c->fresh('lines');
        });
    }

    /** One of the church's own kinds of gathering, or none. */
    private function gatheringType(Territory $place, $id): ?int
    {
        if (empty($id)) {
            return null;
        }
        $type = \App\Models\GatheringType::where('territory_id', $place->id)->find((int) $id);
        if (! $type) {
            throw ValidationException::withMessages(['gathering_type_id' => ['Pick one of this church\'s gatherings.']]);
        }

        return $type->id;
    }

    /** What a church's count window offers: the kinds of giving, the services, that day's gatherings. */
    public function options(Territory $place, ?string $date = null): array
    {
        $this->chart->ensureStandard();
        $funds = \App\Models\AccountingFund::pluck('id', 'code');
        $acc = fn ($code) => AccountingAccount::whereNull('territory_id')->where('code', $code)->value('id');
        $date ??= now()->toDateString();

        return [
            'presets' => collect(self::PRESETS)->map(fn ($v, $label) => ['label' => $label, 'account_id' => $acc($v[0]), 'fund_id' => $funds[$v[1]] ?? null])->values(),
            // What the collection was for: Sunday service, the church's own kinds of gathering (Tuesday fellowship, Kesha...), or something else typed in.
            'services' => collect([['id' => null, 'name' => 'Sunday service']])
                ->concat(\App\Models\GatheringType::where('territory_id', $place->id)->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($g) => ['id' => $g->id, 'name' => $g->name]))
                ->values(),
            'gatherings' => ChurchAttendanceRecord::with(['gatheringType:id,name', 'gatheringCategory:id,name'])
                ->where('territory_type', 'church')->where('territory_id', $place->id)->whereDate('service_date', $date)->orderBy('id')->get()
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->event_name ?: ($r->gatheringType?->name ?? $r->gatheringCategory?->name ?? 'Service'), 'gathering_type_id' => $r->gathering_type_id])->values(),
        ];
    }

    /** Waiting or sent back on dates in a month - a month can't close with these. */
    public static function waitingIn(Territory $place, string $from, string $to): int
    {
        return Collection::where('territory_id', $place->id)->whereIn('status', ['counted', 'returned'])->whereBetween('date', [$from, $to])->count();
    }

    /** Confirmed with cash, not banked yet. */
    public static function unbanked(Territory $place, ?string $upTo = null): array
    {
        $q = Collection::where('territory_id', $place->id)->where('status', 'posted')->whereNull('banking_journal_id')->where('cash_total', '>', 0)
            ->when($upTo, fn ($w) => $w->where('date', '<=', $upTo));

        return ['count' => (clone $q)->count(), 'total' => round((float) (clone $q)->sum('cash_total'), 2)];
    }

    private function check(Territory $place, array $data, User $user): array
    {
        if ($place->territory_type->value !== 'church') {
            throw ValidationException::withMessages(['collection' => ['Collections are counted by churches.']]);
        }
        $this->docs->notFuture($data['date']);
        $cash = $this->docs->cashAccount($place, (int) ($data['cash_account_id'] ?? $this->chart->account('cash_at_hand')->id), 'cash_account_id');
        $lines = [];
        $cashTotal = $mpesaTotal = 0;
        foreach ($data['lines'] ?? [] as $i => $l) {
            $account = $this->docs->postable($place, (int) $l['account_id'], "lines.{$i}.account_id");
            if (! in_array($account->type, ['income', 'liability'], true)) {
                throw ValidationException::withMessages(["lines.{$i}.account_id" => ['Pick what the money was given for - an income account (or money held for others).']]);
            }
            $c = round(max(0, (float) ($l['cash_amount'] ?? 0)), 2);
            $m = round(max(0, (float) ($l['mpesa_amount'] ?? 0)), 2);
            if ($c <= 0 && $m <= 0) {
                continue;
            }
            $cashTotal += $c;
            $mpesaTotal += $m;
            $lines[] = [
                'label' => mb_substr(trim((string) ($l['label'] ?? '')) ?: $account->name, 0, 100),
                'account_id' => $account->id,
                'fund_id' => $this->docs->fund($l['fund_id'] ?? null, "lines.{$i}.fund_id") ?? $this->chart->generalFund()->id,
                'cash_amount' => $c,
                'mpesa_amount' => $m,
            ];
        }
        if (! $lines) {
            throw ValidationException::withMessages(['lines' => ['Enter what was collected.']]);
        }
        $cashTotal = round($cashTotal, 2);
        $mpesaTotal = round($mpesaTotal, 2);

        $den = null;
        if (! empty($data['denominations'])) {
            $den = [];
            $sum = 0;
            foreach (\App\Models\CashCount::DENOMINATIONS as $d) {
                $n = (int) ($data['denominations'][$d] ?? 0);
                if ($n > 0) {
                    $den[$d] = $n;
                    $sum += $n * (int) $d;
                }
            }
            if ($den && abs($sum - $cashTotal) >= 0.005) {
                throw ValidationException::withMessages(['denominations' => ['The notes and coins add up to KES '.number_format($sum, 2).' but the cash entered is KES '.number_format($cashTotal, 2).'.']]);
            }
            $den = $den ?: null;
        }

        $mpesa = null;
        if ($mpesaTotal > 0) {
            $mpesa = ! empty($data['mpesa_account_id'])
                ? $this->docs->cashAccount($place, (int) $data['mpesa_account_id'], 'mpesa_account_id')
                : $this->chart->placeAccount($place, 'mpesa', $user->id);
        }

        $attendance = null;
        if (! empty($data['attendance_record_id'])) {
            $attendance = ChurchAttendanceRecord::where('territory_type', 'church')->where('territory_id', $place->id)->find((int) $data['attendance_record_id']);
            if (! $attendance) {
                throw ValidationException::withMessages(['attendance_record_id' => ['That gathering isn\'t this church\'s.']]);
            }
        }
        $witnesses = array_values(array_filter(array_map(fn ($w) => mb_substr(trim((string) $w), 0, 100), (array) ($data['witnesses'] ?? []))));

        return [
            'collection' => [
                'date' => $data['date'],
                'title' => mb_substr(trim((string) ($data['title'] ?? '')) ?: 'Sunday service', 0, 150),
                'attendance_record_id' => $attendance?->id,
                'gathering_type_id' => $attendance?->gathering_type_id ?? $this->gatheringType($place, $data['gathering_type_id'] ?? null),
                'cash_account_id' => $cash->id,
                'mpesa_account_id' => $mpesa?->id,
                'denominations' => $den,
                'cash_total' => $cashTotal,
                'mpesa_total' => $mpesaTotal,
                'total' => round($cashTotal + $mpesaTotal, 2),
                'witnesses' => $witnesses ?: null,
                'notes' => isset($data['notes']) ? mb_substr(trim((string) $data['notes']), 0, 255) : null,
            ],
            'lines' => $lines,
        ];
    }

    private function assertStatus(Collection $c, array $allowed, string $message): void
    {
        if (! in_array($c->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }
}
