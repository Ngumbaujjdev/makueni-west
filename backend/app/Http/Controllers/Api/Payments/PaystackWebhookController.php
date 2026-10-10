<?php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use App\Models\Gift;
use App\Models\PaymentEvent;
use App\Services\Accounting\Giving;
use App\Services\Accounting\Payouts;
use App\Services\Payments\Paystack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Paystack's webhook (docs/specs/accounting-spec.md, A10a). Checked with the
 * secret key - HMAC-SHA512 of the raw body, as Paystack signs it (the
 * v1-events webhook never passed, because it checked a secret nobody set).
 * Even a signed charge.success is verified with Paystack again before the
 * gift is completed, and completing is once only. Logged as it arrived.
 * A10c: a refund or a dispute on a gift (refund.processed, charge.dispute.*).
 */
class PaystackWebhookController extends Controller
{
    public function __construct(private Giving $giving) {}

    public function handle(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $signed = false;
        try {
            $signed = Paystack::diocese()->signed($body, $request->header('x-paystack-signature'));
        } catch (Throwable) {
            // Not set up: nothing is signed.
        }
        $payload = json_decode($body, true) ?: [];
        $event = PaymentEvent::create(['provider' => 'paystack', 'kind' => mb_substr((string) ($payload['event'] ?? 'unknown'), 0, 30), 'key_ok' => $signed, 'ip' => $request->ip(),
            'payload' => $payload, 'status' => $signed ? 'received' : 'ignored', 'error' => $signed ? null : 'Not signed by Paystack.']);
        if (! $signed) {
            return response()->json(['message' => 'Not signed.'], 401);
        }
        try {
            $kind = (string) ($payload['event'] ?? '');
            $d = (array) ($payload['data'] ?? []);
            $ref = (string) match ($kind) {
                'charge.success' => $d['reference'] ?? '',
                'refund.processed' => $d['transaction_reference'] ?? $d['transaction']['reference'] ?? '',
                'charge.dispute.create', 'charge.dispute.resolve' => $d['transaction']['reference'] ?? $d['transaction_reference'] ?? '',
                default => '',
            };
            $gift = $ref !== '' ? Gift::where('reference', $ref)->first() : null;
            if ($gift) {
                $payouts = app(Payouts::class);
                $cents = fn ($v) => $v !== null && $v !== '' ? round((int) $v / 100, 2) : null;
                $gift = match ($kind) {
                    'charge.success' => $this->giving->complete($gift),
                    // A10c: refunds and disputes, signed like everything else.
                    'refund.processed' => $payouts->refund($gift, (float) $cents($d['amount'] ?? null), 'Paystack refund '.($d['id'] ?? $d['refund_reference'] ?? '')),
                    'charge.dispute.create' => $payouts->disputed($gift),
                    'charge.dispute.resolve' => $payouts->disputeResolved($gift, (string) ($d['resolution'] ?? ''), $cents($d['refund_amount'] ?? null)),
                };
                $event->update(['status' => 'handled', 'subject_type' => 'gift', 'subject_id' => $gift->id]);
            } else {
                $event->update(['status' => 'ignored', 'error' => 'Not a gift we know, or not an event we act on.']);
            }
        } catch (Throwable $e) {
            report($e);
            $event->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        return response()->json(['received' => true]);
    }
}
