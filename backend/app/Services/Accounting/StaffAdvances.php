<?php

namespace App\Services\Accounting;

use App\Models\AdvanceRetirement;
use App\Models\PaymentVoucher;
use App\Models\Requisition;
use App\Models\StaffAdvance;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff advances (docs/specs/accounting-spec.md, A4): money given ahead
 * (Dr 1200 Staff advances when its voucher is paid), accounted for later -
 * what was spent, with receipts, and the change brought back:
 * Dr expenses + Dr cash / Cr 1200. Until then it shows as outstanding, and
 * past its date the person can't get another.
 */
final class StaffAdvances
{
    public function __construct(private Chart $chart, private Ledger $ledger, private Documents $docs, private BudgetBridge $bridge) {}

    public function issue(Requisition $r, PaymentVoucher $pv): StaffAdvance
    {
        $holder = User::find($r->requested_by);

        return StaffAdvance::create([
            'territory_id' => $r->territory_id,
            'user_id' => $holder?->id,
            'holder_name' => $holder?->full_name ?? $pv->payee_name,
            'requisition_id' => $r->id,
            'payment_voucher_id' => $pv->id,
            'purpose' => $r->purpose,
            'amount' => $r->amount,
            'issued_on' => $pv->paid_on ?? now()->toDateString(),
            'due_on' => now()->addDays(Requisitions::advanceDays())->toDateString(),
            'status' => 'open',
        ]);
    }

    /**
     * @param  array{date: string, lines?: array<int, array{account_id: int, amount: numeric, fund_id?: ?int, memo?: ?string}>, returned?: numeric, return_account_id?: ?int}  $data
     */
    public function retire(StaffAdvance $a, User $user, array $data): StaffAdvance
    {
        if ($a->status !== 'open') {
            throw ValidationException::withMessages(['advance' => ['This advance is accounted for already.']]);
        }
        $place = Territory::findOrFail($a->territory_id);
        $this->docs->notFuture($data['date']);
        $lines = [];
        $spent = 0;
        foreach ($data['lines'] ?? [] as $i => $l) {
            if (round((float) ($l['amount'] ?? 0), 2) <= 0) {
                continue;
            }
            $account = $this->docs->postable($place, (int) $l['account_id'], "lines.{$i}.account_id");
            if ($account->isCash()) {
                throw ValidationException::withMessages(["lines.{$i}.account_id" => ['Pick what the money was spent on.']]);
            }
            $amount = $this->docs->amount($l['amount'], "lines.{$i}.amount");
            $spent += $amount;
            $lines[] = ['account_id' => $account->id, 'debit' => $amount, 'fund_id' => $this->docs->fund($l['fund_id'] ?? null, "lines.{$i}.fund_id"),
                'budget_line_id' => $this->docs->budgetLine($place, $account, null, "lines.{$i}.account_id"), 'memo' => $l['memo'] ?? null];
        }
        $returned = round(max(0, (float) ($data['returned'] ?? 0)), 2);
        if ($returned > 0) {
            $back = $this->docs->cashAccount($place, (int) ($data['return_account_id'] ?? $this->chart->account('cash_at_hand')->id), 'return_account_id');
            $lines[] = ['account_id' => $back->id, 'debit' => $returned, 'memo' => 'Change returned'];
        }
        $total = round($spent + $returned, 2);
        if ($total <= 0) {
            throw ValidationException::withMessages(['lines' => ['Enter what was spent, or the change returned.']]);
        }
        if ($total - $a->outstanding() > 0.004) {
            throw ValidationException::withMessages(['lines' => ['That is more than the KES '.number_format($a->outstanding(), 2).' still to account for - pay the extra with a payment voucher.']]);
        }
        $lines[] = ['account_id' => $this->chart->account('staff_advances')->id, 'credit' => $total, 'memo' => "Advance to {$a->holder_name}"];

        return DB::transaction(function () use ($a, $user, $data, $place, $lines, $spent, $returned) {
            $journal = $this->ledger->post($place, [
                'doc_type' => 'payment',
                'date' => $data['date'],
                'narration' => "Accounting for the advance to {$a->holder_name}: {$a->purpose}",
                'party_name' => $a->holder_name,
                'source_type' => 'advance_retirement',
                'source_id' => $a->id,
            ], $lines, $user);
            $this->bridge->journalPosted($journal, $user);
            AdvanceRetirement::create(['advance_id' => $a->id, 'date' => $data['date'], 'spent' => $spent, 'returned' => $returned, 'journal_id' => $journal->id, 'recorded_by' => $user->id]);
            $a->update(['spent' => round((float) $a->spent + $spent, 2), 'returned' => round((float) $a->returned + $returned, 2)]);
            if ($a->fresh()->outstanding() <= 0.004) {
                $a->update(['status' => 'retired']);
            }

            return $a->fresh('retirements');
        });
    }
}
