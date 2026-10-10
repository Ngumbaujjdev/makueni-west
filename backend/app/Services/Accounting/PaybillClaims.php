<?php

namespace App\Services\Accounting;

use App\Models\Journal;
use App\Models\MpesaPayment;
use App\Models\PaymentClaim;
use App\Models\Territory;
use App\Models\User;
use App\Services\Payments\Daraja;
use App\Services\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "I paid by Pay Bill - here's my M-Pesa code" (docs/specs/accounting-spec.md,
 * A10f). A code we already have is answered at once. Otherwise Safaricom is
 * asked (Transaction Status, answered later to our result address): a
 * completed payment into our paybill is recorded - once per code - for the
 * place and purpose the giver chose, like any paybill payment. When Safaricom
 * can't be asked (no API operator yet), the claim waits for the treasurer.
 * Also the hourly Pull of the paybill's payments, catching lost callbacks.
 */
final class PaybillClaims
{
    public function __construct(private Paybill $paybill, private Settings $settings) {}

    public static function cleanCode(?string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
    }

    public function claim(Territory $place, array $d, ?string $ip, ?User $by = null, bool $again = false): PaymentClaim
    {
        $code = self::cleanCode($d['code'] ?? '');
        if (! preg_match('/^[A-Z0-9]{10}$/', $code)) {
            throw ValidationException::withMessages(['code' => ['That doesn\'t look like an M-Pesa code - it has 10 letters and numbers, e.g. SJK1ABC234.']]);
        }
        $purpose = (string) ($d['purpose'] ?? '');
        if (! app(GivingPurposes::class)->usable($purpose)) {
            throw ValidationException::withMessages(['purpose' => ['Pick what you gave for.']]);
        }
        $name = trim((string) ($d['name'] ?? ''));
        $phone = trim((string) ($d['phone'] ?? ''));
        if (! $by && mb_strlen($name) < 2) {
            throw ValidationException::withMessages(['name' => ['Please enter your full name.']]);
        }
        if (! $by && ! Daraja::validPhone($phone)) {
            throw ValidationException::withMessages(['phone' => ['Please enter the M-Pesa number you paid from, e.g. 0712 345 678.']]);
        }
        $fields = ['trans_id' => $code, 'territory_id' => $place->id, 'purpose' => $purpose, 'giver_name' => $name !== '' ? mb_substr($name, 0, 150) : null,
            'giver_phone' => $phone !== '' && Daraja::validPhone($phone) ? Daraja::phone($phone) : null, 'claimed_by' => $by?->id, 'ip' => $ip];

        // Already with us (its callback came): answered at once.
        if ($payment = MpesaPayment::where('trans_id', $code)->first()) {
            if (! $payment->payer_name && $fields['giver_name']) {
                $payment->update(['payer_name' => $fields['giver_name']]);
            }

            return PaymentClaim::create($fields + ['status' => 'confirmed', 'amount' => $payment->amount, 'mpesa_payment_id' => $payment->id, 'result' => 'Already received']);
        }
        // Already being checked: the same claim, not a second question to Safaricom - unless the treasurer asks again.
        $open = PaymentClaim::where('trans_id', $code)->whereIn('status', ['checking', 'waiting', 'failed'])->where('created_at', '>', now()->subDay())->latest('id')->first();
        if ($open && ! $again && $open->status !== 'failed') {
            return $open;
        }
        $claim = $open && $again ? tap($open)->update(['status' => 'checking', 'result' => null]) : PaymentClaim::create($fields + ['status' => 'checking']);
        try {
            $daraja = Daraja::diocese();
            if (! $daraja->canCheck()) {
                $claim->update(['status' => 'waiting', 'result' => 'Safaricom can\'t be asked from here yet - the church treasurer checks it.']);

                return $claim;
            }
            $out = $daraja->transactionStatus($code, $this->paybill->callbackUrl('status-result'), $this->paybill->callbackUrl('status-timeout'), "Check {$code}");
            $claim->update(['originator_id' => $out['OriginatorConversationID'] ?? null, 'conversation_id' => $out['ConversationID'] ?? null]);
        } catch (Throwable $e) {
            report($e);
            $claim->update(['status' => 'waiting', 'result' => mb_substr('Safaricom couldn\'t be asked just now - the church treasurer checks it. ('.$e->getMessage().')', 0, 255)]);
        }

        return $claim->fresh();
    }

    /**
     * Safaricom's answer to a Transaction Status question (or its time-out).
     * A completed payment into our paybill with the code claimed is recorded
     * for the claim's place and purpose - once, whoever asked first.
     */
    public function answered(array $result, bool $timedOut = false): ?PaymentClaim
    {
        $claim = PaymentClaim::query()
            ->where(fn ($q) => $q->where('originator_id', (string) ($result['OriginatorConversationID'] ?? '-'))->orWhere('conversation_id', (string) ($result['ConversationID'] ?? '-')))
            ->latest('id')->first();
        if (! $claim || $claim->status !== 'checking') {
            return $claim;
        }
        if ($timedOut) {
            $claim->update(['status' => 'waiting', 'result' => 'Safaricom took too long to answer - the church treasurer checks it.', 'raw' => $result]);

            return $claim;
        }
        $p = Daraja::resultParameters($result);
        $fail = fn (string $why) => tap($claim)->update(['status' => 'failed', 'result' => mb_substr($why, 0, 255), 'raw' => $result]);
        if ((int) ($result['ResultCode'] ?? -1) !== 0) {
            return $fail((string) ($result['ResultDesc'] ?? 'Safaricom has no such payment.'));
        }
        if (strcasecmp((string) ($p['TransactionStatus'] ?? ''), 'Completed') !== 0) {
            return $fail('Safaricom says that payment is '.strtolower((string) ($p['TransactionStatus'] ?? 'not complete')).'.');
        }
        if (self::cleanCode($p['ReceiptNo'] ?? $claim->trans_id) !== $claim->trans_id) {
            return $fail('Safaricom answered about a different payment.');
        }
        $shortcode = (string) $this->settings->system('paybill.shortcode');
        if ($shortcode === '' || ! str_contains((string) ($p['CreditPartyName'] ?? ''), $shortcode)) {
            return $fail('That payment wasn\'t made to our paybill.');
        }
        [$phone, $payer] = array_pad(array_map('trim', explode(' - ', (string) ($p['DebitPartyName'] ?? ''), 2)), 2, null);
        $when = $p['FinalisedTime'] ?? $p['InitiatedTime'] ?? null;
        $place = Territory::findOrFail($claim->territory_id);
        $payment = $this->paybill->record([
            'trans_id' => $claim->trans_id, 'kind' => 'c2b', 'shortcode' => $shortcode, 'amount' => (float) ($p['Amount'] ?? 0),
            'phone' => $phone ?: $claim->giver_phone, 'payer_name' => $payer ?: $claim->giver_name,
            'bill_ref' => app(GivingPurposes::class)->accountNumber($place, $claim->purpose), 'paid_at' => $this->time($when), 'raw' => $result,
        ]);
        $claim->update(['status' => 'confirmed', 'amount' => $payment->amount, 'mpesa_payment_id' => $payment->id, 'result' => 'Confirmed by Safaricom', 'raw' => $result]);

        return $claim->fresh();
    }

    private function time($value): CarbonImmutable
    {
        try {
            return $value ? CarbonImmutable::createFromFormat('YmdHis', (string) $value, 'Africa/Nairobi') : CarbonImmutable::now();
        } catch (Throwable) {
            return CarbonImmutable::now();
        }
    }

    /** What the giving page (or the treasurer) sees about a claim. */
    public function present(PaymentClaim $c): array
    {
        $payment = $c->mpesa_payment_id ? MpesaPayment::find($c->mpesa_payment_id) : null;
        $journal = $payment ? Journal::find($payment->place_journal_id ?? $payment->diocese_journal_id) : null;

        return [
            'id' => $c->id, 'code' => $c->trans_id, 'status' => $c->status, 'status_label' => PaymentClaim::STATUSES[$c->status], 'result' => $c->result,
            'amount' => $c->amount !== null ? (float) $c->amount : null, 'purpose' => GivingPurposes::label($c->purpose, $c->purpose),
            'place' => Territory::find($c->territory_id)?->name, 'receipt' => $journal?->number, 'sorted' => $payment ? $payment->status === 'posted' : null,
        ];
    }

    // ------------------------------------------------------------ Pull: catching lost callbacks

    /** Can the paybill's payments be fetched? (Safaricom approves Pull on the live paybill; a nominated phone is given.) */
    public function canPull(): bool
    {
        return $this->settings->system('paybill.environment') === 'production' && (bool) $this->settings->system('paybill.pull_number');
    }

    public function pullRegister(): array
    {
        return Daraja::diocese()->pullRegister((string) $this->settings->system('paybill.pull_number'), $this->paybill->callbackUrl('pull'));
    }

    /** The paybill's payments of the last $hours, recorded when we don't have them yet. @return array{seen: int, recorded: int} */
    public function pull(int $hours = 3): array
    {
        $daraja = Daraja::diocese();
        $from = now('Africa/Nairobi')->subHours($hours)->format('Y-m-d H:i:s');
        $to = now('Africa/Nairobi')->format('Y-m-d H:i:s');
        $done = ['seen' => 0, 'recorded' => 0];
        for ($offset = 0, $page = 0; $page < 20; $page++) {
            $rows = $daraja->pullQuery($from, $to, $offset);
            if (! $rows) {
                break;
            }
            foreach ($rows as $t) {
                $done['seen']++;
                $code = self::cleanCode($t['transactionId'] ?? '');
                if ($code === '' || MpesaPayment::where('trans_id', $code)->exists()) {
                    continue;
                }
                $this->paybill->record([
                    'trans_id' => $code, 'kind' => 'c2b', 'shortcode' => $daraja->shortcode, 'amount' => (float) ($t['amount'] ?? 0),
                    'phone' => isset($t['msisdn']) ? (string) $t['msisdn'] : null, 'payer_name' => $t['sender'] ?? null, 'bill_ref' => (string) ($t['billreference'] ?? ''),
                    'paid_at' => isset($t['trxDate']) ? CarbonImmutable::parse((string) $t['trxDate'], 'Africa/Nairobi') : now(), 'raw' => $t,
                ]);
                $done['recorded']++;
            }
            $offset += count($rows);
        }

        return $done;
    }
}
