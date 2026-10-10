<?php

namespace App\Services\Accounting;

use App\Jobs\SendGiftReceipt;
use App\Models\AccountingAccount;
use App\Models\Gift;
use App\Models\MpesaPayment;
use App\Models\MpesaRequest;
use App\Models\PaymentChannel;
use App\Models\PaystackSettlement;
use App\Models\Remittance;
use App\Models\Territory;
use App\Services\Payments\Daraja;
use App\Services\Payments\Paystack;
use App\Services\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Online giving (docs/specs/accounting-spec.md, A10a). The public giving page
 * starts a gift - M-Pesa through the diocese paybill's prompt (A8), or
 * Paystack's page. A Paystack gift is completed once, whichever comes first
 * (the signed webhook, the giver's return, or the sweep), always after
 * checking with Paystack; it posts to the church's books with Paystack's fee
 * and the diocese share split off at source, or - for a church without a
 * subaccount - is held by the diocese and settled monthly like the paybill.
 * M-Pesa goes through the church's own paybill when it has one on (A10b,
 * PayHero or its own Daraja), straight into its books.
 */
final class Giving
{
    public function __construct(private Paybill $paybill, private Ledger $ledger, private Chart $chart, private BudgetBridge $bridge, private Numbering $numbering, private Settings $settings) {}

    // ------------------------------------------------------------ the page

    /** The place for a giving link: SHR027, CCI-MWD-SHR-027, mml002... */
    public function placeFor(string $code): ?Territory
    {
        $full = Territory::where('code', strtoupper(trim($code)))->first();

        return $full ?? $this->paybill->parse(preg_replace('/[^A-Za-z0-9]/', '', $code).'T')['place'];
    }

    public function channel(Territory $place): ?PaymentChannel
    {
        return PaymentChannel::where('territory_id', $place->id)->where('provider', 'paystack')->where('status', 'active')->whereNotNull('subaccount_code')->first();
    }

    /** What the public page shows - never a figure from the books. */
    public function page(Territory $place): array
    {
        $paybillReady = (bool) ($this->settings->system('paybill.shortcode') && $this->settings->system('paybill.passkey') && $this->settings->system('paybill.consumer_key'));
        $own = $this->paybill->mpesaChannel($place);
        $paybill = $own
            ? ['number' => $own->account_number, 'till' => $own->account_name === 'Till', 'accounts' => collect(Paybill::PURPOSES)->map(fn ($p, $k) => ['label' => $p[0], 'account' => Paybill::SUFFIX[$k]])->values()->all()]
            : ($paybillReady ? ['number' => $this->settings->system('paybill.shortcode'), 'till' => false, 'accounts' => array_values($this->paybill->accountNumbers($place))] : null);

        return [
            'place' => ['name' => $place->name, 'code' => Paybill::code($place), 'level' => $place->territory_type->value],
            'logo' => url("/api/settings/logo/{$place->id}"),
            'purposes' => collect(Paybill::PURPOSES)->map(fn ($p, $k) => ['key' => $k, 'label' => $p[0]])->values(),
            'methods' => ['mpesa' => $own !== null || $paybillReady, 'paystack' => Paystack::ready()],
            'paybill' => $paybill,
            'note' => $this->settings->system('giving.page_note'),
        ];
    }

    // ------------------------------------------------------------ starting a gift

    /** @return array{reference: string, method: string, payment_url?: string} */
    public function start(Territory $place, array $data, ?string $ip): array
    {
        $method = ($data['method'] ?? '') === 'paystack' ? 'paystack' : 'mpesa';
        $purpose = (string) ($data['purpose'] ?? '');
        if (! isset(Paybill::PURPOSES[$purpose])) {
            throw ValidationException::withMessages(['purpose' => ['Pick what you are giving for.']]);
        }
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount < 10 || $amount > ($method === 'mpesa' ? 250000 : 1000000)) {
            throw ValidationException::withMessages(['amount' => [$method === 'mpesa' ? 'Give between KES 10 and 250,000 by M-Pesa.' : 'Give between KES 10 and 1,000,000.']]);
        }
        $phone = trim((string) ($data['phone'] ?? ''));
        if ($method === 'mpesa' && ! Daraja::validPhone($phone)) {
            throw ValidationException::withMessages(['phone' => ['Enter your M-Pesa number, e.g. 0712 345 678.']]);
        }
        $email = trim((string) ($data['email'] ?? ''));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => ['That email doesn\'t look right.']]);
        }
        $gift = Gift::create([
            'reference' => 'GFT-'.now('Africa/Nairobi')->format('ymd').'-'.strtoupper(Str::random(6)),
            'territory_id' => $place->id, 'purpose' => $purpose, 'amount' => $method === 'mpesa' ? round($amount) : $amount,
            'giver_name' => mb_substr(trim((string) ($data['name'] ?? '')), 0, 150) ?: null,
            'giver_phone' => $phone !== '' ? Daraja::phone($phone) : null, 'giver_email' => $email ?: null,
            'method' => $method, 'status' => 'pending', 'ip' => $ip,
        ]);
        if ($method === 'mpesa') {
            try {
                $request = $this->paybill->ask($place, null, $phone, (float) $gift->amount, $purpose, $gift->giver_name);
            } catch (ValidationException $e) {
                $gift->update(['status' => 'failed', 'result' => mb_substr(collect($e->errors())->flatten()->first() ?? 'M-Pesa refused', 0, 255)]);
                throw $e;
            }
            $gift->update(['mpesa_request_id' => $request->id, 'provider_ref' => $request->checkout_request_id, 'channel' => $this->channelName($request)]);

            return ['reference' => $gift->reference, 'method' => 'mpesa'];
        }
        $channel = $this->channel($place);
        $split = $channel ? $this->shareFor($place, $purpose, (float) $gift->amount) : 0.0;
        try {
            $out = Paystack::diocese()->initialize((float) $gift->amount, $email ?: "give+{$gift->reference}@".(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'example.org'),
                $gift->reference, url('/api/give/callback'), ['gift' => $gift->reference, 'place' => $place->name, 'purpose' => Paybill::PURPOSES[$purpose][0]],
                $channel?->subaccount_code, $split);
        } catch (Throwable $e) {
            $gift->update(['status' => 'failed', 'result' => mb_substr($e->getMessage(), 0, 255)]);
            throw ValidationException::withMessages(['method' => ['Card giving isn\'t available just now - try M-Pesa.']]);
        }
        $gift->update(['provider_ref' => $gift->reference, 'split' => $split, 'channel' => $channel ? 'subaccount' : 'diocese']);

        return ['reference' => $gift->reference, 'method' => 'paystack', 'payment_url' => (string) ($out['authorization_url'] ?? '')];
    }

    /**
     * The diocese share on a gift: each diocese rule that is a % of the
     * account this purpose posts to (e.g. 10% of tithes), applied to it.
     */
    public function shareFor(Territory $place, string $purpose, float $amount): float
    {
        [$account] = $this->paybill->target($purpose);
        $diocese = $this->paybill->diocese();
        $remittances = app(Remittances::class);
        $rate = $remittances->rulesOwed($place)
            ->filter(fn ($d) => (int) $d->territory_id === (int) $diocese->id && $d->deduction_type === 'percentage' && in_array($account->id, $remittances->basisAccounts($d), true))
            ->sum(fn ($d) => (float) $d->deduction_value);

        return round($amount * $rate / 100, 2);
    }

    // ------------------------------------------------------------ completing a Paystack gift

    /**
     * Complete a Paystack gift - always after checking with Paystack - once:
     * the gift row is locked and its status checked again.
     */
    public function complete(Gift $gift, ?array $verified = null): Gift
    {
        if ($gift->method !== 'paystack' || $gift->status === 'paid') {
            return $gift;
        }
        $v = $verified ?? Paystack::diocese()->verify($gift->reference);
        $status = (string) ($v['status'] ?? '');
        if ($status !== 'success') {
            if (in_array($status, ['failed', 'abandoned', 'reversed'], true)) {
                $gift->update(['status' => $status === 'abandoned' ? 'abandoned' : 'failed', 'result' => mb_substr((string) ($v['gateway_response'] ?? $status), 0, 255), 'raw' => $v]);
            }

            return $gift->fresh();
        }
        if ((int) ($v['amount'] ?? 0) !== Paystack::cents((float) $gift->amount) || strtoupper((string) ($v['currency'] ?? '')) !== 'KES' || ($v['reference'] ?? '') !== $gift->reference) {
            $gift->update(['result' => 'Paystack\'s figures don\'t match the gift - check it on Paystack.', 'raw' => $v]);

            return $gift->fresh();
        }

        return DB::transaction(function () use ($gift, $v) {
            $gift = Gift::whereKey($gift->id)->lockForUpdate()->firstOrFail();
            if ($gift->status === 'paid') {
                return $gift;
            }
            $place = Territory::findOrFail($gift->territory_id);
            $fee = round((int) ($v['fees'] ?? 0) / 100, 2);
            $toSub = ! empty($v['subaccount']) || ! empty($v['fees_split']);
            $split = $toSub ? round((int) ($v['fees_split']['integration'] ?? Paystack::cents((float) $gift->split)) / 100, 2) : 0.0;
            $paidAt = isset($v['paid_at']) ? \Carbon\CarbonImmutable::parse($v['paid_at']) : now();
            $fields = $this->post($gift, $place, (float) $gift->amount, $fee, $split, $toSub, $paidAt->setTimezone('Africa/Nairobi')->toDateString());
            $gift->update($fields + ['status' => 'paid', 'fee' => $fee, 'split' => $split, 'net' => round((float) $gift->amount - $fee - $split, 2), 'paid_at' => $paidAt,
                'channel' => $toSub ? 'subaccount' : 'diocese', 'result' => (string) ($v['channel'] ?? 'paid'), 'raw' => $v]);
            DB::afterCommit(fn () => SendGiftReceipt::dispatch($gift->id));

            return $gift->fresh();
        });
    }

    /** Post a paid Paystack gift (see the spec). @return array<string, int|null> */
    private function post(Gift $gift, Territory $place, float $gross, float $fee, float $split, bool $toSub, string $date): array
    {
        $diocese = $this->paybill->diocese();
        [$account, $fund] = $this->paybill->target($gift->purpose);
        $label = Paybill::PURPOSES[$gift->purpose][0];
        $clearing = $this->chart->account('online_clearing');
        $charges = $this->chart->account('bank_charges');
        $head = fn (string $narration, string $party) => ['doc_type' => 'receipt', 'date' => $date, 'narration' => $narration, 'party_name' => $party,
            'party_phone' => $gift->giver_phone, 'method' => 'card', 'reference' => $gift->reference, 'source_type' => 'gift', 'source_id' => $gift->id];
        $giver = $gift->giver_name ?: 'Online giver';
        $income = ['account_id' => $account->id, 'credit' => $gross, 'fund_id' => $fund?->id, 'budget_line_id' => $this->chart->budgetLineFor($place, $account->id)?->id, 'memo' => "{$label} given online"];
        $feeLine = $fee > 0 ? [['account_id' => $charges->id, 'debit' => $fee, 'budget_line_id' => $this->chart->budgetLineFor($place, $charges->id)?->id, 'memo' => 'Paystack\'s fee']] : [];

        if ((int) $place->id === (int) $diocese->id) {
            $j = $this->ledger->post($diocese, $head("{$label} given online", $giver), [['account_id' => $clearing->id, 'debit' => round($gross - $fee, 2)], ...$feeLine, $income], null);
            $this->bridge->journalPosted($j, null);

            return ['journal_id' => $j->id];
        }
        if (! $toSub) {
            // The diocese's own Paystack account took it: held for the church, settled monthly with the paybill money.
            $net = round($gross - $fee, 2);
            $dj = $this->ledger->post($diocese, $head("{$label} for {$place->name} given online", $giver), [
                ['account_id' => $clearing->id, 'debit' => $net],
                ['account_id' => $this->chart->account('held_for_others')->id, 'credit' => $net, 'memo' => $place->name, 'for_territory_id' => $place->id],
            ], null);
            $pj = $this->ledger->post($place, $head("{$label} given online (held by the diocese)", $giver), [
                ['account_id' => $this->chart->account('held_by_diocese')->id, 'debit' => $net, 'memo' => 'Held by the diocese until it settles', 'for_territory_id' => $diocese->id], ...$feeLine, $income,
            ], null);
            $this->bridge->journalPosted($pj, null);

            return ['journal_id' => $pj->id, 'diocese_journal_id' => $dj->id];
        }
        // The church's subaccount took it, with the diocese share split off at source.
        $net = round($gross - $fee - $split, 2);
        $shareLines = [];
        $rules = [];
        if ($split > 0) {
            [$rules, $shareLines] = $this->shareLines($place, $gift->purpose, $split);
        }
        $pj = $this->ledger->post($place, $head("{$label} given online", $giver), [['account_id' => $clearing->id, 'debit' => $net], ...$feeLine, ...$shareLines, $income], null);
        $this->bridge->journalPosted($pj, null);
        $out = ['journal_id' => $pj->id];
        if ($split > 0) {
            $contributions = AccountingAccount::whereNull('territory_id')->where('code', '4100')->firstOrFail();
            $dj = $this->ledger->post($diocese, $head("Diocese share of a gift to {$place->name}", $place->name), [
                ['account_id' => $clearing->id, 'debit' => $split],
                ['account_id' => $contributions->id, 'credit' => $split, 'budget_line_id' => $this->chart->budgetLineFor($diocese, $contributions->id)?->id, 'memo' => $place->name, 'for_territory_id' => $place->id],
            ], null);
            $this->bridge->journalPosted($dj, null);
            $month = substr($date, 0, 7);
            $rule = $rules[0] ?? null;
            $rem = Remittance::create([
                'number' => $this->numbering->next($place, 'remittance', (int) substr($date, 0, 4)),
                'from_territory_id' => $place->id, 'to_territory_id' => $diocese->id, 'kind' => 'share', 'budget_deduction_id' => $rule?->id,
                'purpose' => ($rule?->name ?? 'Diocese share').' split off a gift online', 'amount' => $split, 'status' => 'confirmed',
                'sent_journal_id' => $pj->id, 'sent_on' => $date, 'method' => 'card', 'reference' => $gift->reference,
                'received_on' => $date, 'received_journal_id' => $dj->id, 'confirmed_at' => now(),
            ]);
            $rem->lines()->create(['month' => $month, 'amount' => $split]);
            $out += ['diocese_journal_id' => $dj->id, 'remittance_id' => $rem->id];
        }

        return $out;
    }

    /** The share's paid-through lines (e.g. 5700 on its budget line), split across the diocese rules it came from. */
    private function shareLines(Territory $place, string $purpose, float $split): array
    {
        [$account] = $this->paybill->target($purpose);
        $diocese = $this->paybill->diocese();
        $remittances = app(Remittances::class);
        $rules = $remittances->rulesOwed($place)
            ->filter(fn ($d) => (int) $d->territory_id === (int) $diocese->id && $d->deduction_type === 'percentage' && in_array($account->id, $remittances->basisAccounts($d), true))->values();
        if ($rules->isEmpty()) {
            return [[], [['account_id' => AccountingAccount::whereNull('territory_id')->where('code', '5700')->firstOrFail()->id, 'debit' => $split, 'memo' => 'Diocese share split off', 'for_territory_id' => $diocese->id]]];
        }
        $total = $rules->sum(fn ($d) => (float) $d->deduction_value);
        $lines = [];
        $left = $split;
        foreach ($rules as $i => $d) {
            $part = $i === $rules->count() - 1 ? round($left, 2) : round($split * (float) $d->deduction_value / $total, 2);
            $left -= $part;
            $lines[] = ['account_id' => $this->chart->forBudgetLine($d->budgetLine)->id, 'debit' => $part, 'budget_line_id' => $d->budget_line_id, 'memo' => "{$d->name} split off", 'for_territory_id' => $diocese->id];
        }

        return [$rules->all(), $lines];
    }

    // ------------------------------------------------------------ M-Pesa gifts (through the paybill)

    /** The paybill prompt behind an M-Pesa gift was answered. */
    public function mpesaAnswered(MpesaRequest $request, ?MpesaPayment $payment): void
    {
        $gift = Gift::where('mpesa_request_id', $request->id)->where('status', 'pending')->first();
        if (! $gift) {
            return;
        }
        $gift->update($payment
            ? ['status' => 'paid', 'mpesa_payment_id' => $payment->id, 'journal_id' => $payment->place_journal_id, 'diocese_journal_id' => $payment->diocese_journal_id, 'paid_at' => $payment->paid_at, 'provider_ref' => $payment->trans_id, 'net' => $gift->amount, 'channel' => $this->channelName($request), 'result' => 'Paid by M-Pesa']
            : ['status' => 'failed', 'result' => $request->result]);
    }

    /** paybill (the diocese's), payhero or own_daraja. */
    private function channelName(MpesaRequest $request): string
    {
        $provider = $request->channel_id ? PaymentChannel::whereKey($request->channel_id)->value('provider') : null;

        return match ($provider) {
            'payhero' => 'payhero',
            'daraja' => 'own_daraja',
            default => 'paybill',
        };
    }

    // ------------------------------------------------------------ the sweep and the payouts

    /** Paystack and PayHero gifts still pending after 10 minutes are checked and completed; after a day, abandoned. */
    public function sweep(): array
    {
        $done = ['checked' => 0, 'paid' => 0, 'abandoned' => 0];
        foreach (Gift::where('status', 'pending')->where('created_at', '<', now()->subMinutes(10))->orderBy('id')->limit(200)->get() as $gift) {
            if ($gift->created_at->lt(now()->subDay())) {
                $gift->update(['status' => 'abandoned', 'result' => 'Not paid within a day.']);
                $done['abandoned']++;

                continue;
            }
            if ($gift->method === 'mpesa') {
                // The church's PayHero: its callback may never have come - ask PayHero.
                $request = $gift->mpesa_request_id ? MpesaRequest::find($gift->mpesa_request_id) : null;
                if ($request && $request->channel_id && $request->status === 'pending') {
                    try {
                        $done['checked']++;
                        $this->paybill->payheroCheck($request);
                        $done['paid'] += $gift->fresh()->status === 'paid' ? 1 : 0;
                    } catch (Throwable $e) {
                        report($e);
                    }
                }

                continue;
            }
            if (! Paystack::ready()) {
                continue;
            }
            try {
                $done['checked']++;
                $done['paid'] += $this->complete($gift)->status === 'paid' ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $done;
    }

    /** Each Paystack payout - a church's subaccount, or the diocese's own - posted once: Dr its bank / Cr clearing. */
    public function recordSettlements(): int
    {
        $n = 0;
        $paystack = Paystack::diocese();
        $diocese = $this->paybill->diocese();
        $channels = PaymentChannel::where('provider', 'paystack')->where('status', 'active')->whereNotNull('settles_into_id')->get();
        foreach ($channels as $ch) {
            $place = Territory::find($ch->territory_id);
            $code = (int) $ch->territory_id === (int) $diocese->id ? 'none' : $ch->subaccount_code;
            if (! $place || ! $code) {
                continue;
            }
            foreach ($paystack->settlements($code, now()->subDays(30)->toDateString()) as $s) {
                $id = (string) ($s['id'] ?? '');
                $amount = round((int) ($s['effective_amount'] ?? $s['total_amount'] ?? 0) / 100, 2);
                if ($id === '' || $amount <= 0 || ! in_array(strtolower((string) ($s['status'] ?? '')), ['success', 'processed'], true) || PaystackSettlement::where('settlement_id', $id)->exists()) {
                    continue;
                }
                DB::transaction(function () use ($s, $id, $amount, $place, $ch, &$n) {
                    $date = substr((string) ($s['settlement_date'] ?? $s['settledAt'] ?? now()->toDateString()), 0, 10);
                    $row = PaystackSettlement::create(['settlement_id' => $id, 'territory_id' => $place->id, 'amount' => $amount, 'settled_on' => $date, 'raw' => $s]);
                    $j = $this->ledger->post($place, ['doc_type' => 'transfer', 'date' => $date, 'narration' => 'Paystack payout', 'method' => 'bank', 'reference' => "PSTK-{$id}",
                        'source_type' => 'paystack_settlement', 'source_id' => $row->id], [
                            ['account_id' => $ch->settles_into_id, 'debit' => $amount],
                            ['account_id' => $this->chart->account('online_clearing')->id, 'credit' => $amount, 'memo' => 'Paystack payout'],
                        ], null);
                    $row->update(['journal_id' => $j->id]);
                    $n++;
                });
            }
        }

        return $n;
    }
}
