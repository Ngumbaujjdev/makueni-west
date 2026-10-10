<?php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use App\Models\PaymentChannel;
use App\Models\PaymentEvent;
use App\Services\Accounting\Paybill;
use App\Services\Payments\Daraja;
use App\Services\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Safaricom's callbacks for the diocese paybill (docs/specs/accounting-spec.md,
 * A8) - and for a church's own Daraja app (A10b), under that channel's key:
 * C2B validation and confirmation, and the answer to an "Ask to pay" prompt. Public, so the callback key in the address is the guard (a wrong
 * key is a 404, nothing more), plus Safaricom's published addresses when the
 * diocese asks for that. Every call is logged as it arrived; Safaricom always
 * gets "accepted" for a payment - refusing would lose a giver's money.
 */
class DarajaController extends Controller
{
    /** The church channel the callback key belongs to (null = the diocese paybill). */
    private ?PaymentChannel $channel = null;

    public function __construct(private Paybill $paybill, private Settings $settings) {}

    public function validation(Request $request, string $key): JsonResponse
    {
        $event = $this->event($request, $key, 'c2b_validation');

        $event?->update(['status' => 'handled']);

        return $event ? $this->accepted() : $this->notFoundKey();
    }

    public function confirmation(Request $request, string $key): JsonResponse
    {
        $event = $this->event($request, $key, 'c2b_confirmation');
        if (! $event) {
            return $this->notFoundKey();
        }
        $d = $request->json()->all();
        try {
            $shortcode = $this->channel ? (string) $this->channel->account_number : (string) $this->settings->system('paybill.shortcode');
            if ((string) ($d['BusinessShortCode'] ?? '') !== $shortcode || empty($d['TransID'])) {
                $event->update(['status' => 'ignored', 'error' => 'Not for our paybill, or no M-Pesa code.']);

                return $this->accepted();
            }
            $payment = $this->paybill->record([
                'channel_id' => $this->channel?->id,
                'trans_id' => (string) $d['TransID'],
                'kind' => 'c2b',
                'shortcode' => (string) $d['BusinessShortCode'],
                'amount' => (float) ($d['TransAmount'] ?? 0),
                'phone' => isset($d['MSISDN']) ? (string) $d['MSISDN'] : null,
                'payer_name' => trim(implode(' ', array_filter([$d['FirstName'] ?? null, $d['MiddleName'] ?? null, $d['LastName'] ?? null]))),
                'bill_ref' => (string) ($d['BillRefNumber'] ?? ''),
                'paid_at' => $this->when($d['TransTime'] ?? null),
                'raw' => $d,
            ]);
            $event->update(['status' => 'handled', 'subject_type' => 'mpesa_payment', 'subject_id' => $payment->id]);
        } catch (Throwable $e) {
            report($e);
            $event->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        return $this->accepted();
    }

    public function stk(Request $request, string $key): JsonResponse
    {
        $event = $this->event($request, $key, 'stk_result');
        if (! $event) {
            return $this->notFoundKey();
        }
        try {
            $callback = (array) $request->json('Body.stkCallback', []);
            $payment = $this->paybill->stkResult($callback, $this->channel);
            $event->update(['status' => $payment ? 'handled' : 'ignored', 'subject_type' => $payment ? 'mpesa_payment' : null, 'subject_id' => $payment?->id,
                'error' => $payment ? null : ($callback['ResultDesc'] ?? 'Not paid')]);
        } catch (Throwable $e) {
            report($e);
            $event->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        return $this->accepted();
    }

    /** Safaricom's answer to "is this M-Pesa code ours?" (Transaction Status, A10f) - or its time-out. */
    public function statusResult(Request $request, string $key): JsonResponse
    {
        return $this->claimAnswer($request, $key, false);
    }

    public function statusTimeout(Request $request, string $key): JsonResponse
    {
        return $this->claimAnswer($request, $key, true);
    }

    private function claimAnswer(Request $request, string $key, bool $timedOut): JsonResponse
    {
        $event = $this->event($request, $key, $timedOut ? 'status_timeout' : 'status_result');
        if (! $event || $this->channel) {
            return $this->notFoundKey();
        }
        try {
            $claim = app(\App\Services\Accounting\PaybillClaims::class)->answered((array) $request->json('Result', []), $timedOut);
            $event->update(['status' => $claim ? 'handled' : 'ignored', 'subject_type' => $claim ? 'payment_claim' : null, 'subject_id' => $claim?->id, 'error' => $claim ? ($claim->status === 'failed' ? $claim->result : null) : 'No claim of ours matches it.']);
        } catch (Throwable $e) {
            report($e);
            $event->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        return $this->accepted();
    }

    /** Pull Transactions' address (A10f) - logged; the payments themselves are fetched by payments:pull. */
    public function pull(Request $request, string $key): JsonResponse
    {
        $event = $this->event($request, $key, 'pull');
        $event?->update(['status' => 'handled']);

        return $event ? $this->accepted() : $this->notFoundKey();
    }

    /** Log the call; null when the key (or, if asked, the caller's address) is wrong. */
    private function event(Request $request, string $key, string $kind): ?PaymentEvent
    {
        $saved = (string) $this->settings->system('paybill.callback_key');
        $keyOk = $saved !== '' && hash_equals($saved, $key);
        if (! $keyOk && strlen($key) >= 20) {
            $channel = PaymentChannel::where('provider', 'daraja')->where('callback_key', $key)->first();
            $keyOk = $channel && hash_equals((string) $channel->callback_key, $key);
            $this->channel = $keyOk ? $channel : null;
        }
        $ipOk = ! $this->settings->system('paybill.safaricom_only') || in_array($request->ip(), Daraja::SAFARICOM_IPS, true);
        $event = PaymentEvent::create(['provider' => 'daraja', 'kind' => $kind, 'subject_type' => $this->channel ? 'payment_channel' : null, 'subject_id' => $this->channel?->id, 'key_ok' => $keyOk && $ipOk, 'ip' => $request->ip(),
            'payload' => $request->json()->all(), 'status' => $keyOk && $ipOk ? 'received' : 'ignored', 'error' => $keyOk ? ($ipOk ? null : 'Not from a Safaricom address.') : 'Wrong callback key.']);

        return $keyOk && $ipOk ? $event : null;
    }

    private function when(?string $transTime): CarbonImmutable
    {
        try {
            return $transTime ? CarbonImmutable::createFromFormat('YmdHis', $transTime, 'Africa/Nairobi') : CarbonImmutable::now();
        } catch (Throwable) {
            return CarbonImmutable::now();
        }
    }

    private function accepted(): JsonResponse
    {
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    private function notFoundKey(): JsonResponse
    {
        return response()->json(['message' => 'Not found.'], 404);
    }
}
