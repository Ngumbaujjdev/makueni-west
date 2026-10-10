<?php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use App\Models\MpesaRequest;
use App\Models\PaymentChannel;
use App\Models\PaymentEvent;
use App\Services\Accounting\Paybill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * PayHero's callback for a church's own paybill (docs/specs/accounting-spec.md,
 * A10b). Public; the channel's callback key in the address is the guard (a
 * wrong key is a 404). PayHero doesn't sign it, so the callback only says
 * which prompt to look at - whether it was paid is asked of PayHero itself.
 */
class PayHeroController extends Controller
{
    public function __construct(private Paybill $paybill) {}

    public function handle(Request $request, string $key): JsonResponse
    {
        $channel = strlen($key) >= 20 ? PaymentChannel::where('provider', 'payhero')->where('callback_key', $key)->first() : null;
        $ok = $channel && hash_equals((string) $channel->callback_key, $key);
        $event = PaymentEvent::create(['provider' => 'payhero', 'kind' => 'stk_result', 'key_ok' => $ok, 'ip' => $request->ip(), 'payload' => $request->json()->all(),
            'status' => $ok ? 'received' : 'ignored', 'error' => $ok ? null : 'Wrong callback key.']);
        if (! $ok) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        try {
            $r = (array) $request->json('response', []);
            $prompt = MpesaRequest::where('channel_id', $channel->id)->where('checkout_request_id', (string) ($r['CheckoutRequestID'] ?? ''))->where('checkout_request_id', '!=', '')->first();
            if (! $prompt && preg_match('/^MR-(\d+)-/', (string) ($r['ExternalReference'] ?? ''), $m)) {
                $prompt = MpesaRequest::where('channel_id', $channel->id)->find((int) $m[1]);
            }
            if (! $prompt) {
                $event->update(['status' => 'ignored', 'error' => 'No prompt of ours matches it.']);
            } else {
                $payment = $this->paybill->payheroCheck($prompt);
                $prompt->refresh();
                $event->update(['status' => $payment ? 'handled' : ($prompt->status === 'failed' ? 'handled' : 'ignored'), 'subject_type' => $payment ? 'mpesa_payment' : 'mpesa_request',
                    'subject_id' => $payment?->id ?? $prompt->id, 'error' => $payment || $prompt->status === 'failed' ? null : 'PayHero doesn\'t have it as paid yet.']);
            }
        } catch (Throwable $e) {
            report($e);
            $event->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        return response()->json(['status' => 'ok']);
    }
}
