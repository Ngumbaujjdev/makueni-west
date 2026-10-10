<?php

namespace App\Services\Accounting;

use App\Jobs\SendPaybillThanks;
use App\Models\AccountingAccount;
use App\Models\AccountingFund;
use App\Models\BudgetDeduction;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\MpesaPayment;
use App\Models\MpesaRequest;
use App\Models\PaybillSettlement;
use App\Models\PaymentChannel;
use App\Models\Remittance;
use App\Models\Territory;
use App\Models\User;
use App\Services\Payments\Daraja;
use App\Services\Payments\PayHero;
use App\Services\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The diocese M-Pesa paybill (docs/specs/accounting-spec.md, A8). Members of
 * every place pay into one diocese paybill with their place's code as the
 * account number. Each payment is recorded once (per M-Pesa code) and posted
 * into both sets of books: the diocese holds the money for the place (Cr 2400)
 * and the place sees its giving at once (Dr 1310 held by the diocese / Cr its
 * income). Payments that match no place wait To sort (Cr 2410). Each month the
 * diocese settles every place: what it holds, less the diocese share the place
 * still owes, is paid by a voucher approved as usual.
 *
 * A church with its own paybill (A10b) takes M-Pesa through its PayHero or
 * its own Daraja app instead: the same payments and prompts, marked with the
 * channel, posted straight into its own books (Dr its M-Pesa / Cr income).
 */
final class Paybill
{
    /** purpose => [label, account code, fund code (null = General)] */
    public const PURPOSES = [
        'T' => ['Tithe', '4000', null],
        'O' => ['Offering', '4010', null],
        'TH' => ['Thanksgiving', '4020', null],
        'B' => ['Building fund', '4020', 'BLD'],
        'K' => ['KYS', '4020', 'KYS'],
    ];

    /** How each purpose is shown after the code - never a lone O, which reads as a zero (MML002O ~ MML0020). */
    public const SUFFIX = ['T' => 'T', 'O' => 'OFF', 'TH' => 'TH', 'B' => 'B', 'K' => 'KYS'];

    /** What givers type after the code => the purpose. */
    private const WORDS = [
        'T' => 'T', 'TITHE' => 'T', 'TITHES' => 'T', 'O' => 'O', 'OFF' => 'O', 'OFFERING' => 'O', 'SADAKA' => 'O',
        'TH' => 'TH', 'THANKS' => 'TH', 'THANKSGIVING' => 'TH', 'B' => 'B', 'BLD' => 'B', 'BUILDING' => 'B', 'K' => 'K', 'KYS' => 'K',
    ];

    public function __construct(private Ledger $ledger, private Chart $chart, private BudgetBridge $bridge, private Numbering $numbering, private Settings $settings) {}

    // ------------------------------------------------------------ places and account numbers

    public function diocese(): Territory
    {
        return Territory::where('territory_type', 'diocese')->orderBy('id')->firstOrFail();
    }

    /** SHR027 for CCI-MWD-SHR-027, SHR for a region, MWD for the diocese. */
    public static function code(Territory $place): string
    {
        return str_replace('-', '', Numbering::short($place));
    }

    /** What a place's members type as the account number, per purpose. @return array<string, array{label: string, account: string}> */
    public function accountNumbers(Territory $place): array
    {
        $code = self::code($place);
        $out = ['' => ['label' => 'Any ('.self::PURPOSES[$this->defaultPurpose()][0].')', 'account' => $code]];
        foreach (self::PURPOSES as $key => [$label]) {
            $out[$key] = ['label' => $label, 'account' => $code.self::SUFFIX[$key]];
        }

        return $out;
    }

    /**
     * The place and purpose an account number means. Case, spaces and dashes
     * don't matter, and a short number is padded (SHR27 = SHR027).
     *
     * @return array{place: ?Territory, purpose: ?string}
     */
    public function parse(?string $billRef): array
    {
        $ref = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $billRef));
        if ($ref === '' || ! preg_match('/^([A-Z]{2,5})(\d{0,4})([A-Z]*)$/', $ref, $m)) {
            return ['place' => null, 'purpose' => null];
        }
        [, $letters, $digits, $rest] = $m;
        $codes = $this->codes();
        $place = null;
        if ($digits !== '') {
            $place = $codes[$letters.str_pad(ltrim($digits, '0') === '' ? '0' : ltrim($digits, '0'), 3, '0', STR_PAD_LEFT)] ?? $codes[$letters.$digits] ?? null;
        } else {
            // Letters only: a region or the diocese - its code may run into the purpose (SHRT, MWDBLD).
            foreach ([0, 1, 2, 3] as $cut) {
                $try = $cut ? substr($letters, 0, -$cut) : $letters;
                if ($try !== '' && isset($codes[$try])) {
                    $place = $codes[$try];
                    $rest = substr($letters, strlen($try)).$rest;
                    break;
                }
            }
        }
        if (! $place) {
            return ['place' => null, 'purpose' => null];
        }
        if ($rest === '') {
            return ['place' => $place, 'purpose' => $this->defaultPurpose()];
        }

        return ['place' => $place, 'purpose' => self::WORDS[$rest] ?? null];
    }

    /** Every place's account code => the place (cached for the request). @return array<string, Territory> */
    private function codes(): array
    {
        static $codes = null;
        if ($codes === null || app()->runningUnitTests()) {
            $codes = Territory::whereIn('territory_type', ['diocese', 'region', 'church'])->whereNotNull('code')->get()
                ->mapWithKeys(fn ($t) => [self::code($t) => $t])->all();
        }

        return $codes;
    }

    private function defaultPurpose(): string
    {
        $p = (string) $this->settings->system('paybill.default_purpose');

        return isset(self::PURPOSES[$p]) ? $p : 'O';
    }

    /** The purpose's account and fund. @return array{0: AccountingAccount, 1: ?AccountingFund} */
    public function target(string $purpose): array
    {
        [, $code, $fund] = self::PURPOSES[$purpose] ?? self::PURPOSES['O'];
        $this->chart->ensureStandard();

        return [AccountingAccount::whereNull('territory_id')->where('code', $code)->firstOrFail(), $fund ? AccountingFund::where('code', $fund)->first() : null];
    }

    /** The diocese's paybill money account (M-Pesa, its shortcode as the number) - made on first use. */
    public function account(?string $shortcode = null): AccountingAccount
    {
        $diocese = $this->diocese();
        $shortcode ??= (string) $this->settings->system('paybill.shortcode');
        $found = AccountingAccount::where('territory_id', $diocese->id)->where('cash_kind', 'mpesa')->where('mpesa_number', $shortcode)->first();

        return $found ?? $this->chart->addPlaceAccount($diocese, 'mpesa', ['name' => "Diocese paybill {$shortcode}", 'mpesa_number' => $shortcode, 'description' => 'The diocese M-Pesa paybill every place\'s members pay into']);
    }

    // ------------------------------------------------------------ payments in

    /**
     * Record a payment - once per M-Pesa code - and post it. A repeat of the
     * same code is returned as it is. Posting that fails (e.g. a closed
     * month) leaves it To sort with the reason; the payment is never lost.
     */
    public function record(array $p): MpesaPayment
    {
        $channel = ! empty($p['channel_id']) ? PaymentChannel::find((int) $p['channel_id']) : null;
        $parsed = $channel
            ? ['place' => Territory::find($channel->territory_id), 'purpose' => $this->parseOwn($channel, $p['bill_ref'] ?? null)]
            : $this->parse($p['bill_ref'] ?? null);
        try {
            $payment = MpesaPayment::create([
                'channel_id' => $channel?->id,
                'trans_id' => strtoupper(trim($p['trans_id'])),
                'kind' => $p['kind'] ?? 'c2b',
                'shortcode' => (string) $p['shortcode'],
                'amount' => round((float) $p['amount'], 2),
                'phone' => isset($p['phone']) ? mb_substr((string) $p['phone'], 0, 60) : null,
                'payer_name' => isset($p['payer_name']) ? (mb_substr(trim((string) $p['payer_name']), 0, 150) ?: null) : null,
                'bill_ref' => isset($p['bill_ref']) ? mb_substr((string) $p['bill_ref'], 0, 60) : null,
                'paid_at' => $p['paid_at'] ?? now(),
                'territory_id' => $parsed['place']?->id,
                'purpose' => $parsed['purpose'],
                'status' => 'to_sort',
                'mpesa_request_id' => $p['mpesa_request_id'] ?? null,
                'raw' => $p['raw'] ?? null,
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || ($e->errorInfo[0] ?? '') === '23000') {
                $existing = MpesaPayment::where('trans_id', strtoupper(trim($p['trans_id'])))->firstOrFail();
                if (! empty($p['mpesa_request_id']) && ! $existing->mpesa_request_id) {
                    $existing->update(['mpesa_request_id' => $p['mpesa_request_id']]);
                }

                return $existing;
            }
            throw $e;
        }
        $this->post($payment);
        $payment->refresh();
        if ($payment->status === 'posted' && $payment->territory_id) {
            DB::afterCommit(fn () => SendPaybillThanks::dispatch($payment->id));
        }

        return $payment;
    }

    /** Post a payment just recorded: for a place, for the diocese, or To sort. */
    private function post(MpesaPayment $payment): void
    {
        $place = $payment->territory_id ? Territory::find($payment->territory_id) : null;
        try {
            DB::transaction(function () use ($payment, $place) {
                $diocese = $this->diocese();
                $paybill = $this->account($payment->shortcode);
                $amount = (float) $payment->amount;
                $date = $payment->paid_at->copy()->setTimezone('Africa/Nairobi')->toDateString();
                $head = fn (string $narration) => ['doc_type' => 'receipt', 'date' => $date, 'narration' => $narration, 'party_name' => $payment->payer_name ?: 'M-Pesa payer',
                    'party_phone' => $payment->phone, 'method' => 'mpesa', 'reference' => $payment->trans_id, 'source_type' => 'mpesa_payment', 'source_id' => $payment->id];
                if ($payment->channel_id && $place && $payment->purpose) {
                    $this->postOwn($payment, $place);

                    return;
                }
                if (! $place || ! $payment->purpose) {
                    $j = $this->ledger->post($diocese, $head("Paybill payment to sort ({$payment->bill_ref})"), [
                        ['account_id' => $paybill->id, 'debit' => $amount],
                        ['account_id' => $this->chart->account('paybill_to_sort')->id, 'credit' => $amount, 'memo' => "Account {$payment->bill_ref}"],
                    ], null);
                    $payment->update(['diocese_journal_id' => $j->id, 'note' => $place ? 'The account number has no purpose we know.' : 'No place has that account number.']);

                    return;
                }
                [$account, $fund] = $this->target($payment->purpose);
                $label = self::PURPOSES[$payment->purpose][0];
                if ((int) $place->id === (int) $diocese->id) {
                    $j = $this->ledger->post($diocese, $head("{$label} by M-Pesa paybill"), [
                        ['account_id' => $paybill->id, 'debit' => $amount],
                        ['account_id' => $account->id, 'credit' => $amount, 'fund_id' => $fund?->id, 'budget_line_id' => $this->chart->budgetLineFor($diocese, $account->id)?->id, 'memo' => $label],
                    ], null);
                    $this->bridge->journalPosted($j, null);
                    $payment->update(['status' => 'posted', 'diocese_journal_id' => $j->id, 'account_id' => $account->id, 'fund_id' => $fund?->id, 'note' => null]);

                    return;
                }
                [$dj, $pj] = $this->postForPlace($payment, $place, $paybill, $account, $fund, $label, $head);
                $payment->update(['status' => 'posted', 'diocese_journal_id' => $dj->id, 'place_journal_id' => $pj->id, 'account_id' => $account->id, 'fund_id' => $fund?->id, 'note' => null]);
            });
        } catch (Throwable $e) {
            report($e);
            $payment->update(['status' => 'to_sort', 'note' => mb_substr('Couldn\'t be posted: '.$e->getMessage(), 0, 255)]);
        }
    }

    /** Into a church's own paybill (A10b): Dr its M-Pesa account / Cr the purpose's income, on its budget line. */
    private function postOwn(MpesaPayment $payment, Territory $place, ?User $by = null): void
    {
        $channel = PaymentChannel::findOrFail($payment->channel_id);
        $into = $this->ownAccount($channel);
        [$account, $fund] = $this->target($payment->purpose);
        $label = self::PURPOSES[$payment->purpose][0];
        $j = $this->ledger->post($place, ['doc_type' => 'receipt', 'date' => $payment->paid_at->copy()->setTimezone('Africa/Nairobi')->toDateString(),
            'narration' => "{$label} by M-Pesa to our paybill", 'party_name' => $payment->payer_name ?: 'M-Pesa payer', 'party_phone' => $payment->phone,
            'method' => 'mpesa', 'reference' => $payment->trans_id, 'source_type' => 'mpesa_payment', 'source_id' => $payment->id], [
                ['account_id' => $into->id, 'debit' => (float) $payment->amount],
                ['account_id' => $account->id, 'credit' => (float) $payment->amount, 'fund_id' => $fund?->id, 'budget_line_id' => $this->chart->budgetLineFor($place, $account->id)?->id, 'memo' => $label],
            ], $by);
        $this->bridge->journalPosted($j, $by);
        $payment->update(['status' => 'posted', 'place_journal_id' => $j->id, 'account_id' => $account->id, 'fund_id' => $fund?->id, 'note' => null]);
    }

    /** The church's M-Pesa account its own paybill money is recorded into. */
    public function ownAccount(PaymentChannel $channel): AccountingAccount
    {
        $into = $channel->settles_into_id ? AccountingAccount::where('territory_id', $channel->territory_id)->where('cash_kind', 'mpesa')->find($channel->settles_into_id) : null;
        if (! $into) {
            throw new \RuntimeException('Pick which of the church\'s M-Pesa accounts its paybill money is recorded into (Gateways).');
        }

        return $into;
    }

    /** What a payment into a church's own paybill was for: its account number names only the purpose (the church's code in front is fine). */
    public function parseOwn(PaymentChannel $channel, ?string $billRef): string
    {
        $ref = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $billRef));
        $place = Territory::find($channel->territory_id);
        $code = $place ? self::code($place) : '';
        if ($code !== '' && str_starts_with($ref, $code)) {
            $ref = substr($ref, strlen($code));
        }

        return self::WORDS[$ref] ?? $this->defaultPurpose();
    }

    /** The church's own M-Pesa route, if it has one on: its own Daraja first, then PayHero. */
    public function mpesaChannel(Territory $place): ?PaymentChannel
    {
        return PaymentChannel::where('territory_id', $place->id)->whereIn('provider', ['daraja', 'payhero'])->where('status', 'active')->get()
            ->filter(fn ($c) => $c->mpesaReady())->sortBy(fn ($c) => $c->provider === 'daraja' ? 0 : 1)->first();
    }

    /** Diocese: Dr paybill (or from to-sort) / Cr held for the place. Place: Dr held by the diocese / Cr its income. @return array{0: Journal, 1: Journal} */
    private function postForPlace(MpesaPayment $payment, Territory $place, ?AccountingAccount $from, AccountingAccount $account, ?AccountingFund $fund, string $label, callable $head): array
    {
        $diocese = $this->diocese();
        $amount = (float) $payment->amount;
        $debit = $from ?? $this->chart->account('paybill_to_sort');
        $dj = $this->ledger->post($diocese, ['doc_type' => $from ? 'receipt' : 'journal'] + $head("{$label} for {$place->name} by M-Pesa paybill"), [
            ['account_id' => $debit->id, 'debit' => $amount],
            ['account_id' => $this->chart->account('held_for_others')->id, 'credit' => $amount, 'memo' => $place->name, 'for_territory_id' => $place->id],
        ], null);
        $pj = $this->ledger->post($place, $head("{$label} by M-Pesa to the diocese paybill"), [
            ['account_id' => $this->chart->account('held_by_diocese')->id, 'debit' => $amount, 'memo' => 'Held by the diocese until it settles', 'for_territory_id' => $diocese->id],
            ['account_id' => $account->id, 'credit' => $amount, 'fund_id' => $fund?->id, 'budget_line_id' => $this->chart->budgetLineFor($place, $account->id)?->id, 'memo' => $label],
        ], null);
        $this->bridge->journalPosted($pj, null);

        return [$dj, $pj];
    }

    /**
     * Sort a payment that is waiting: to a place and purpose, to the diocese
     * as income, or returned to the payer (a voucher approved as usual).
     */
    public function sort(MpesaPayment $payment, User $user, array $data): MpesaPayment
    {
        return DB::transaction(function () use ($payment, $user, $data) {
            $payment = MpesaPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'to_sort') {
                throw ValidationException::withMessages(['payment' => ['It was already sorted.']]);
            }
            if ($payment->channel_id) {
                // It came into a church's own paybill: it can only go into that church's books.
                $purpose = (string) ($data['purpose'] ?? '');
                if (! isset(self::PURPOSES[$purpose])) {
                    throw ValidationException::withMessages(['purpose' => ['Pick what it was given for.']]);
                }
                $place = Territory::findOrFail(PaymentChannel::findOrFail($payment->channel_id)->territory_id);
                $payment->update(['territory_id' => $place->id, 'purpose' => $purpose]);
                $this->postOwn($payment, $place, $user);
                $payment->update(['note' => isset($data['note']) ? (mb_substr(trim((string) $data['note']), 0, 255) ?: null) : null, 'sorted_by' => $user->id, 'sorted_at' => now()]);

                return $payment->fresh();
            }
            $to = (string) ($data['to'] ?? '');
            $diocese = $this->diocese();
            $paybill = $payment->diocese_journal_id ? null : $this->account($payment->shortcode);
            $head = fn (string $narration) => ['doc_type' => 'receipt', 'date' => $payment->paid_at->copy()->setTimezone('Africa/Nairobi')->toDateString(), 'narration' => $narration, 'party_name' => $payment->payer_name ?: 'M-Pesa payer',
                'party_phone' => $payment->phone, 'method' => 'mpesa', 'reference' => $payment->trans_id, 'source_type' => 'mpesa_payment', 'source_id' => $payment->id];
            if ($to === 'return') {
                $reason = trim((string) ($data['note'] ?? ''));
                if ($reason === '') {
                    throw ValidationException::withMessages(['note' => ['Say why it goes back.']]);
                }
                if (! $payment->diocese_journal_id) {
                    throw ValidationException::withMessages(['to' => ['Put it in the books first (sort it to the diocese or a place) - or ask the bookkeeper.']]);
                }
                $pv = app(PaymentVouchers::class)->prepare($diocese, $user, [
                    'date' => now()->toDateString(), 'payee_name' => $payment->payer_name ?: 'M-Pesa payer', 'payee_phone' => $payment->phone,
                    'pay_from_account_id' => $this->account($payment->shortcode)->id, 'narration' => "Paybill payment {$payment->trans_id} returned: {$reason}",
                    'lines' => [['account_id' => $this->chart->account('paybill_to_sort')->id, 'amount' => (float) $payment->amount, 'description' => "Returned {$payment->trans_id}"]],
                ]);
                $payment->update(['status' => 'returned', 'return_voucher_id' => $pv->id, 'note' => mb_substr($reason, 0, 255), 'sorted_by' => $user->id, 'sorted_at' => now()]);

                return $payment->fresh();
            }
            $purpose = (string) ($data['purpose'] ?? '');
            if (! isset(self::PURPOSES[$purpose])) {
                throw ValidationException::withMessages(['purpose' => ['Pick what it was given for.']]);
            }
            [$account, $fund] = $this->target($purpose);
            $label = self::PURPOSES[$purpose][0];
            $place = $to === 'diocese' ? $diocese : Territory::whereIn('territory_type', ['church', 'region'])->find((int) ($data['territory_id'] ?? 0));
            if (! $place) {
                throw ValidationException::withMessages(['territory_id' => ['Pick the church it was for.']]);
            }
            if ((int) $place->id === (int) $diocese->id) {
                $j = $this->ledger->post($diocese, $head("{$label} by M-Pesa paybill (sorted)"), [
                    ['account_id' => ($paybill ?? $this->chart->account('paybill_to_sort'))->id, 'debit' => (float) $payment->amount],
                    ['account_id' => $account->id, 'credit' => (float) $payment->amount, 'fund_id' => $fund?->id, 'budget_line_id' => $this->chart->budgetLineFor($diocese, $account->id)?->id, 'memo' => $label],
                ], $user);
                $this->bridge->journalPosted($j, $user);
                $fields = $paybill ? ['diocese_journal_id' => $j->id] : ['sort_journal_id' => $j->id];
            } else {
                [$dj, $pj] = $this->postForPlace($payment, $place, $paybill, $account, $fund, $label, $head);
                $fields = ($paybill ? ['diocese_journal_id' => $dj->id] : ['sort_journal_id' => $dj->id]) + ['place_journal_id' => $pj->id];
            }
            $payment->update($fields + ['status' => 'posted', 'territory_id' => $place->id, 'purpose' => $purpose, 'account_id' => $account->id, 'fund_id' => $fund?->id,
                'note' => isset($data['note']) ? (mb_substr(trim((string) $data['note']), 0, 255) ?: null) : null, 'sorted_by' => $user->id, 'sorted_at' => now()]);

            return $payment->fresh();
        });
    }

    // ------------------------------------------------------------ ask to pay (STK)

    /**
     * Send the M-Pesa prompt to a phone, with the place's account number
     * filled in - through the church's own paybill when it has one on (A10b),
     * else the diocese paybill.
     */
    public function ask(Territory $place, ?User $user, string $phone, float $amount, string $purpose, ?string $name = null): MpesaRequest
    {
        if (! Daraja::validPhone($phone)) {
            throw ValidationException::withMessages(['phone' => ['Enter a Safaricom number, e.g. 0712 345 678.']]);
        }
        $amount = round($amount);
        if ($amount < 1 || $amount > 250000) {
            throw ValidationException::withMessages(['amount' => ['M-Pesa takes 1 to 250,000 shillings at a time.']]);
        }
        if (! isset(self::PURPOSES[$purpose])) {
            throw ValidationException::withMessages(['purpose' => ['Pick what it is for.']]);
        }
        $ref = self::code($place).self::SUFFIX[$purpose];
        $channel = $this->mpesaChannel($place);
        if ($channel) {
            return $this->askOwn($channel, $place, $user, $phone, $amount, $purpose, $ref, $name);
        }
        $daraja = Daraja::diocese();
        $request = MpesaRequest::create(['territory_id' => $place->id, 'account_ref' => $ref, 'amount' => $amount, 'phone' => Daraja::phone($phone), 'shortcode' => $daraja->shortcode, 'requested_by' => $user?->id]);
        try {
            $out = $daraja->stkPush($phone, $amount, $ref, mb_substr(self::PURPOSES[$purpose][0], 0, 13), $this->callbackUrl('stk'));
        } catch (Throwable $e) {
            $request->update(['status' => 'failed', 'result' => mb_substr($e->getMessage(), 0, 255)]);
            throw ValidationException::withMessages(['phone' => ['M-Pesa didn\'t take it: '.$e->getMessage()]]);
        }
        $request->update(['merchant_request_id' => $out['MerchantRequestID'] ?? null, 'checkout_request_id' => $out['CheckoutRequestID'] ?? null, 'result' => $out['CustomerMessage'] ?? null]);

        return $request->fresh();
    }

    private function askOwn(PaymentChannel $channel, Territory $place, ?User $user, string $phone, float $amount, string $purpose, string $ref, ?string $name): MpesaRequest
    {
        $request = MpesaRequest::create(['channel_id' => $channel->id, 'territory_id' => $place->id, 'account_ref' => $ref, 'amount' => $amount, 'phone' => Daraja::phone($phone),
            'shortcode' => (string) $channel->account_number, 'requested_by' => $user?->id]);
        try {
            if ($channel->provider === 'daraja') {
                $out = Daraja::forChannel($channel)->stkPush($phone, $amount, $ref, mb_substr(self::PURPOSES[$purpose][0], 0, 13), $this->channelUrl($channel, 'stk'));
                $request->update(['merchant_request_id' => $out['MerchantRequestID'] ?? null, 'checkout_request_id' => $out['CheckoutRequestID'] ?? null, 'result' => $out['CustomerMessage'] ?? null]);
            } else {
                $out = PayHero::forChannel($channel)->stkPush($amount, $phone, "MR-{$request->id}-{$ref}", $name, $this->channelUrl($channel));
                $request->update(['merchant_request_id' => $out['reference'] ?: null, 'checkout_request_id' => $out['checkout_request_id'] ?: null, 'result' => $out['status'] ?: null]);
            }
        } catch (Throwable $e) {
            $request->update(['status' => 'failed', 'result' => mb_substr($e->getMessage(), 0, 255)]);
            throw ValidationException::withMessages(['phone' => ['M-Pesa didn\'t take it: '.$e->getMessage()]]);
        }

        return $request->fresh();
    }

    /**
     * A PayHero prompt: ask PayHero how it went (its callback isn't signed,
     * so it is never taken at its word) - paid becomes a payment, once per
     * M-Pesa code; failed is kept on the request; still queued waits.
     */
    public function payheroCheck(MpesaRequest $request): ?MpesaPayment
    {
        if ($request->status === 'paid') {
            return $request->mpesa_payment_id ? MpesaPayment::find($request->mpesa_payment_id) : null;
        }
        $channel = $request->channel_id ? PaymentChannel::find($request->channel_id) : null;
        if ($request->status !== 'pending' || ! $channel || $channel->provider !== 'payhero' || ! $request->merchant_request_id) {
            return null;
        }
        $s = PayHero::forChannel($channel)->status($request->merchant_request_id);
        if ($s['status'] === 'FAILED') {
            $request->update(['status' => 'failed', 'result' => mb_substr((string) ($s['raw']['ResultDesc'] ?? $s['raw']['result_desc'] ?? 'Not paid'), 0, 255)]);
            app(Giving::class)->mpesaAnswered($request->fresh(), null);

            return null;
        }
        if ($s['status'] !== 'SUCCESS' || ! $s['receipt']) {
            return null;
        }
        if ($s['amount'] !== null && round($s['amount']) !== round((float) $request->amount)) {
            $request->update(['result' => 'PayHero\'s amount doesn\'t match the prompt - check it on PayHero.']);

            return null;
        }
        $payment = $this->record([
            'channel_id' => $channel->id, 'trans_id' => (string) $s['receipt'], 'kind' => 'stk', 'shortcode' => (string) $channel->account_number,
            'amount' => (float) $request->amount, 'phone' => $request->phone, 'bill_ref' => $request->account_ref,
            'paid_at' => now(), 'mpesa_request_id' => $request->id, 'raw' => $s['raw'],
        ]);
        $request->update(['status' => 'paid', 'result' => 'Paid', 'mpesa_payment_id' => $payment->id]);
        app(Giving::class)->mpesaAnswered($request->fresh(), $payment);

        return $payment;
    }

    /**
     * The prompt's answer from Safaricom: paid becomes a payment; anything
     * else is kept on the request. $channel is whose callback key it came
     * with (null = the diocese's) - it only answers that paybill's prompts.
     */
    public function stkResult(array $callback, ?PaymentChannel $channel = null): ?MpesaPayment
    {
        $request = MpesaRequest::where('checkout_request_id', $callback['CheckoutRequestID'] ?? '')->first();
        if (! $request || (int) $request->channel_id !== (int) $channel?->id) {
            return null;
        }
        if ((int) ($callback['ResultCode'] ?? -1) !== 0) {
            if ($request->status === 'pending') {
                $request->update(['status' => 'failed', 'result' => mb_substr((string) ($callback['ResultDesc'] ?? 'Not paid'), 0, 255)]);
                app(Giving::class)->mpesaAnswered($request->fresh(), null);
            }

            return null;
        }
        $m = Daraja::metadata($callback);
        $payment = $this->record([
            'trans_id' => (string) ($m['MpesaReceiptNumber'] ?? ''),
            'channel_id' => $request->channel_id,
            'kind' => 'stk',
            'shortcode' => $request->shortcode,
            'amount' => (float) ($m['Amount'] ?? $request->amount),
            'phone' => (string) ($m['PhoneNumber'] ?? $request->phone),
            'bill_ref' => $request->account_ref,
            'paid_at' => isset($m['TransactionDate']) ? CarbonImmutable::createFromFormat('YmdHis', (string) $m['TransactionDate'], 'Africa/Nairobi') : now(),
            'mpesa_request_id' => $request->id,
            'raw' => $callback,
        ]);
        $request->update(['status' => 'paid', 'result' => 'Paid', 'mpesa_payment_id' => $payment->id]);
        app(Giving::class)->mpesaAnswered($request->fresh(), $payment);

        return $payment;
    }

    // ------------------------------------------------------------ callbacks setup

    /** The callback key - made (and saved) the first time it is needed. */
    public function callbackKey(?User $user = null): string
    {
        $key = (string) $this->settings->system('paybill.callback_key');
        if ($key === '') {
            $key = Str::random(40);
            $this->settings->setMany($this->diocese(), 'diocese', 'paybill', ['paybill.callback_key' => $key], [], [], $user);
        }

        return $key;
    }

    public function callbackUrl(string $what): string
    {
        return "{$this->callbackBase()}/payments/daraja/{$this->callbackKey()}/{$what}";
    }

    private function callbackBase(): string
    {
        $base = rtrim((string) ($this->settings->system('paybill.callback_base') ?: config('app.url')), '/');

        return str_ends_with($base, '/api') ? $base : $base.'/api';
    }

    /** A church channel's callback address (A10b): /payments/daraja/{key}/{what} or /payments/payhero/{key}. */
    public function channelUrl(PaymentChannel $channel, string $what = ''): string
    {
        if (! $channel->callback_key) {
            $channel->forceFill(['callback_key' => Str::random(40)])->save();
        }

        return "{$this->callbackBase()}/payments/{$channel->provider}/{$channel->callback_key}".($channel->provider === 'daraja' ? "/{$what}" : '');
    }

    /** Send a church's own Daraja addresses to Safaricom. */
    public function registerChannel(PaymentChannel $channel): array
    {
        $this->ownAccount($channel);

        return Daraja::forChannel($channel)->registerUrls($this->channelUrl($channel, 'confirmation'), $this->channelUrl($channel, 'validation'));
    }

    public function register(User $user): array
    {
        $this->callbackKey($user);
        $out = Daraja::diocese()->registerUrls($this->callbackUrl('confirmation'), $this->callbackUrl('validation'));
        $this->account();

        return $out;
    }

    // ------------------------------------------------------------ settling the places

    /**
     * What the diocese holds for a place for a month: paybill money for it up
     * to the month's end, less everything already taken from what it holds
     * (netted shares, settlements paid) and settlements still being paid.
     */
    public function held(Territory $place, string $month): float
    {
        $diocese = $this->diocese();
        $end = date('Y-m-t', strtotime("{$month}-01"));
        $held = $this->chart->account('held_for_others')->id;
        $base = fn () => JournalLine::query()->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.territory_id', $diocese->id)->where('journal_lines.account_id', $held)->where('journal_lines.for_territory_id', $place->id);
        // Paybill money in for the place up to the month's end (a reversal of one counts when it reverses)...
        $in = (float) $base()->whereIn('journals.source_type', ['mpesa_payment', 'gift'])->where('journal_lines.date', '<=', $end)->selectRaw('COALESCE(SUM(journal_lines.credit - journal_lines.debit), 0) as v')->value('v');
        // ...less everything taken from it - netted shares, settlements paid, and their reversals - whenever it happened.
        $out = (float) $base()->whereIn('journals.source_type', ['paybill_settlement', 'payment_voucher'])->selectRaw('COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0) as v')->value('v');
        $waiting = (float) PaybillSettlement::where('territory_id', $place->id)->where('status', 'prepared')->sum('net');

        return round(max($in - $out - $waiting, 0), 2);
    }

    /**
     * The diocese share a place still owes up to a month, rule by rule and
     * month by month, taken from what is held (never more).
     *
     * @return list<array{rule: BudgetDeduction, month: string, amount: float}>
     */
    private function shareFrom(Territory $place, string $month, float $held): array
    {
        $diocese = $this->diocese();
        $remittances = app(Remittances::class);
        $take = [];
        $left = $held;
        foreach ($remittances->rulesOwed($place)->filter(fn ($d) => (int) $d->territory_id === (int) $diocese->id) as $rule) {
            $owing = collect($remittances->owing($place, (int) substr($month, 0, 4)))->firstWhere('id', $rule->id);
            foreach ($owing['months'] ?? [] as $m) {
                if ($m['month'] > $month || $m['owed'] <= 0 || $left <= 0) {
                    continue;
                }
                $amount = round(min($m['owed'], $left), 2);
                $take[] = ['rule' => $rule, 'month' => $m['month'], 'amount' => $amount];
                $left = round($left - $amount, 2);
            }
        }

        return $take;
    }

    /** Each place with paybill money held for a month: held, the share to net, the net to pay. */
    public function preview(string $month): array
    {
        $this->assertMonth($month);
        $diocese = $this->diocese();
        $ids = JournalLine::where('territory_id', $diocese->id)->where('account_id', $this->chart->account('held_for_others')->id)->whereNotNull('for_territory_id')
            ->distinct()->pluck('for_territory_id');

        return Territory::whereIn('id', $ids)->orderBy('name')->get()->map(function (Territory $t) use ($month) {
            $held = $this->held($t, $month);
            $share = round(array_sum(array_column($this->shareFrom($t, $month, $held), 'amount')), 2);

            return ['place' => ['id' => $t->id, 'name' => $t->name, 'code' => $t->code], 'held' => $held, 'share' => $share, 'net' => round($held - $share, 2),
                'settled' => PaybillSettlement::where('territory_id', $t->id)->where('month', $month)->whereIn('status', ['prepared', 'paid'])->exists()];
        })->filter(fn ($r) => $r['held'] > 0)->values()->all();
    }

    /**
     * Settle a month for these places: the share is netted at once (both
     * books, and a confirmed share remittance so A6 shows it sent); the net
     * goes as a settlement remittance whose voucher is approved as usual.
     *
     * @return list<PaybillSettlement>
     */
    public function settle(string $month, array $placeIds, User $user, int $payFromAccountId): array
    {
        $this->assertMonth($month);
        $diocese = $this->diocese();
        $out = [];
        foreach (Territory::whereIn('id', $placeIds)->whereIn('territory_type', ['church', 'region'])->get() as $place) {
            $out[] = DB::transaction(function () use ($month, $place, $user, $payFromAccountId, $diocese) {
                DB::table('territories')->where('id', $place->id)->lockForUpdate()->first();
                if (PaybillSettlement::where('territory_id', $place->id)->where('month', $month)->whereIn('status', ['prepared', 'paid'])->exists()) {
                    throw ValidationException::withMessages(['month' => ["{$place->name} is already settled for ".date('F Y', strtotime("{$month}-01")).'.']]);
                }
                $held = $this->held($place, $month);
                if ($held <= 0) {
                    throw ValidationException::withMessages(['places' => ["The diocese holds nothing for {$place->name}."]]);
                }
                $take = $this->shareFrom($place, $month, $held);
                $share = round(array_sum(array_column($take, 'amount')), 2);
                $net = round($held - $share, 2);
                $settlement = PaybillSettlement::create(['territory_id' => $place->id, 'month' => $month, 'held' => $held, 'share' => $share, 'net' => $net, 'status' => $net > 0 ? 'prepared' : 'paid', 'prepared_by' => $user->id]);
                $label = date('F Y', strtotime("{$month}-01"));
                if ($share > 0) {
                    $this->net($settlement, $place, $diocese, $take, $user, $label);
                }
                if ($net > 0) {
                    $rem = Remittance::create([
                        'number' => $this->numbering->next($diocese, 'remittance', (int) now()->year),
                        'from_territory_id' => $diocese->id, 'to_territory_id' => $place->id, 'kind' => 'settlement',
                        'purpose' => "Paybill money for {$label}", 'amount' => $net, 'status' => 'waiting', 'created_by' => $user->id,
                    ]);
                    $rem->lines()->create(['month' => $month, 'amount' => $net]);
                    $pv = app(PaymentVouchers::class)->prepare($diocese, $user, [
                        'date' => now()->toDateString(), 'payee_name' => $place->name, 'pay_from_account_id' => $payFromAccountId,
                        'narration' => "{$rem->number}: paybill money for {$label} to {$place->name}", 'purpose' => 'remittance', 'remittance_id' => $rem->id,
                        'lines' => [['account_id' => $this->chart->account('held_for_others')->id, 'amount' => $net, 'description' => "Paybill money for {$label}", 'for_territory_id' => $place->id]],
                    ]);
                    $rem->update(['payment_voucher_id' => $pv->id]);
                    $settlement->update(['remittance_id' => $rem->id]);
                }

                return $settlement->fresh();
            });
        }

        return $out;
    }

    /** Net the share: diocese Dr held / Cr contributions; place Dr the share's account / Cr held by the diocese; a confirmed share remittance per rule. */
    private function net(PaybillSettlement $s, Territory $place, Territory $diocese, array $take, User $user, string $label): void
    {
        $contributions = AccountingAccount::whereNull('territory_id')->where('code', '4100')->firstOrFail();
        $date = now()->toDateString();
        $dj = $this->ledger->post($diocese, ['doc_type' => 'receipt', 'date' => $date, 'narration' => "Diocese share netted from {$place->name}'s paybill money ({$label})",
            'party_name' => $place->name, 'source_type' => 'paybill_settlement', 'source_id' => $s->id], [
                ['account_id' => $this->chart->account('held_for_others')->id, 'debit' => (float) $s->share, 'memo' => $place->name, 'for_territory_id' => $place->id],
                ['account_id' => $contributions->id, 'credit' => (float) $s->share, 'budget_line_id' => $this->chart->budgetLineFor($diocese, $contributions->id)?->id, 'memo' => $place->name, 'for_territory_id' => $place->id],
            ], $user);
        $this->bridge->journalPosted($dj, $user);
        $lines = [];
        foreach (collect($take)->groupBy(fn ($t) => $t['rule']->id) as $parts) {
            $rule = $parts->first()['rule'];
            $lines[] = ['account_id' => $this->chart->forBudgetLine($rule->budgetLine)->id, 'debit' => round($parts->sum('amount'), 2), 'budget_line_id' => $rule->budget_line_id,
                'memo' => "{$rule->name} netted from paybill money", 'for_territory_id' => $diocese->id];
        }
        $lines[] = ['account_id' => $this->chart->account('held_by_diocese')->id, 'credit' => (float) $s->share, 'memo' => 'Netted by the diocese', 'for_territory_id' => $diocese->id];
        $pj = $this->ledger->post($place, ['doc_type' => 'payment', 'date' => $date, 'narration' => "Diocese share netted from paybill money ({$label})", 'party_name' => $diocese->name,
            'source_type' => 'paybill_settlement', 'source_id' => $s->id], $lines, $user);
        $this->bridge->journalPosted($pj, $user);
        $shareIds = [];
        foreach (collect($take)->groupBy(fn ($t) => $t['rule']->id) as $parts) {
            $rule = $parts->first()['rule'];
            $rem = Remittance::create([
                'number' => $this->numbering->next($place, 'remittance', (int) now()->year),
                'from_territory_id' => $place->id, 'to_territory_id' => $diocese->id, 'kind' => 'share', 'budget_deduction_id' => $rule->id,
                'purpose' => "{$rule->name} netted from paybill money ({$label})", 'amount' => round($parts->sum('amount'), 2), 'status' => 'confirmed',
                'sent_journal_id' => $pj->id, 'sent_on' => $date, 'method' => 'mpesa', 'reference' => 'Paybill settlement',
                'received_on' => $date, 'received_journal_id' => $dj->id, 'confirmed_by' => $user->id, 'confirmed_at' => now(), 'created_by' => $user->id,
            ]);
            $rem->lines()->createMany($parts->map(fn ($t) => ['month' => $t['month'], 'amount' => $t['amount']])->values()->all());
            $shareIds[] = $rem->id;
        }
        $s->update(['diocese_journal_id' => $dj->id, 'place_journal_id' => $pj->id, 'share_remittance_id' => $shareIds[0] ?? null]);
    }

    /** Cancel a settlement not yet paid: its voucher is cancelled (which undoes the netting). */
    public function cancel(PaybillSettlement $s, User $user): void
    {
        if ($s->status !== 'prepared') {
            throw ValidationException::withMessages(['settlement' => ['Only a settlement not yet paid can be cancelled.']]);
        }
        $pv = $s->remittance?->voucher;
        if ($pv && $pv->status !== 'cancelled') {
            app(PaymentVouchers::class)->cancel($pv, $user);   // -> Remittances::voucherCancelled -> undoSettlement

            return;
        }
        $this->undoSettlement($s, $user);
    }

    /** Undo the netting and mark the settlement cancelled. */
    public function undoSettlement(PaybillSettlement $s, ?User $user): void
    {
        DB::transaction(function () use ($s, $user) {
            foreach ([$s->diocese_journal_id, $s->place_journal_id] as $id) {
                $j = $id ? Journal::find($id) : null;
                if ($j && $j->status === 'posted') {
                    $this->ledger->reverse($j, $user, 'Paybill settlement cancelled', max(now()->toDateString(), $j->date->toDateString()));
                    $this->bridge->journalReversed($j, $user);
                }
            }
            Remittance::where('kind', 'share')->where('from_territory_id', $s->territory_id)->where('reference', 'Paybill settlement')
                ->where('sent_journal_id', $s->place_journal_id)->update(['status' => 'cancelled']);
            $s->update(['status' => 'cancelled']);
        });
    }

    private function assertMonth(string $month): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || $month >= now()->format('Y-m')) {
            throw ValidationException::withMessages(['month' => ['Pick a month that has ended.']]);
        }
    }
}
