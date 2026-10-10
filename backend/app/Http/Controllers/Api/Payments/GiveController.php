<?php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use App\Models\Gift;
use App\Models\Journal;
use App\Models\PaymentClaim;
use App\Models\Territory;
use App\Services\Accounting\Giving;
use App\Services\Accounting\Paybill;
use App\Services\Accounting\PaybillClaims;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The public giving page's API (docs/specs/accounting-spec.md, A10a) - no
 * sign-in. It shows a place's name and how to give, starts a gift, follows it,
 * and brings the giver back from Paystack. It never shows any figure from the
 * books, and a gift is only ever paid after checking with Paystack or M-Pesa.
 */
class GiveController extends Controller
{
    public function __construct(private Giving $giving) {}

    public function show(string $code): JsonResponse
    {
        $place = $this->giving->placeFor($code);

        return $place ? response()->json(['success' => true, 'data' => $this->giving->page($place)]) : $this->missing();
    }

    public function store(Request $request, string $code): JsonResponse
    {
        $place = $this->giving->placeFor($code);
        if (! $place) {
            return $this->missing();
        }
        $data = $request->validate([
            'purpose' => ['required', 'string', 'max:3'],
            'amount' => ['required', 'numeric'],
            'method' => ['required', 'in:mpesa,paystack'],
            'name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'max:150'],
        ], ['amount.required' => 'How much are you giving?', 'purpose.required' => 'Pick what you are giving for.']);

        return response()->json(['success' => true, 'data' => $this->giving->start($place, $data, $request->ip()),
            'message' => $data['method'] === 'mpesa' ? 'Check your phone and enter your M-Pesa PIN.' : 'Opening the secure payment page...'], 201);
    }

    /** GET /give/status/{reference} - where a gift stands (a Paystack gift or an M-Pesa prompt still waiting is checked). */
    public function status(string $reference): JsonResponse
    {
        $gift = Gift::where('reference', $reference)->first();
        if (! $gift) {
            return $this->missing('We can\'t find that gift.');
        }
        if ($gift->status === 'pending' && $gift->method === 'paystack' && $gift->created_at->lt(now()->subSeconds(20))) {
            try {
                $gift = $this->giving->complete($gift);
            } catch (Throwable $e) {
                report($e);
            }
        }
        // An M-Pesa prompt whose answer hasn't come back (A10, dev or a lost callback): ask M-Pesa.
        if ($gift->status === 'pending' && $gift->method === 'mpesa' && $gift->mpesa_request_id && $gift->created_at->lt(now()->subSeconds(25))) {
            try {
                $request = \App\Models\MpesaRequest::find($gift->mpesa_request_id);
                $request && app(Paybill::class)->checkPrompt($request);
                $gift = $gift->fresh();
            } catch (Throwable $e) {
                report($e);
            }
        }
        $place = Territory::find($gift->territory_id);

        return response()->json(['success' => true, 'data' => [
            'reference' => $gift->reference, 'status' => $gift->status, 'amount' => (float) $gift->amount,
            // How it was paid, for the thanks page's logo: Paystack records its channel (card, mobile_money) on a paid gift.
            'paid_with' => $gift->status !== 'paid' ? null : ($gift->method === 'mpesa' || $gift->result === 'mobile_money' ? 'mpesa' : 'card'),
            'purpose' => Paybill::PURPOSES[$gift->purpose][0] ?? null, 'place' => $place?->name, 'code' => $place ? Paybill::code($place) : null,
            'receipt' => $gift->status === 'paid' ? Journal::find($gift->journal_id)?->number : null,
            'result' => $gift->status === 'failed' ? $gift->result : null,
        ]]);
    }

    /** POST /give/{code}/claim {code, purpose, name, phone} - "I paid by Pay Bill - here's my M-Pesa code" (A10f). */
    public function claim(Request $request, string $code): JsonResponse
    {
        $place = $this->giving->placeFor($code);
        if (! $place) {
            return $this->missing();
        }
        $data = $request->validate(['code' => ['required', 'string', 'max:20'], 'purpose' => ['required', 'string', 'max:3'], 'name' => ['nullable', 'string', 'max:150'], 'phone' => ['nullable', 'string', 'max:20']],
            ['code.required' => 'Enter the M-Pesa code from your confirmation message.']);
        $claim = app(PaybillClaims::class)->claim($place, $data, $request->ip());

        return response()->json(['success' => true, 'data' => app(PaybillClaims::class)->present($claim), 'message' => match ($claim->status) {
            'confirmed' => 'Received - thank you.',
            'checking' => 'Checking with Safaricom...',
            'waiting' => 'Thank you - the church treasurer will confirm it.',
            default => (string) $claim->result,
        }], 201);
    }

    /** GET /give/claim/{id}?code= - where a claim stands (the code must match: claims aren't browsable). */
    public function claimStatus(Request $request, int $id): JsonResponse
    {
        $claim = PaymentClaim::find($id);
        if (! $claim || PaybillClaims::cleanCode($request->query('code')) !== $claim->trans_id) {
            return $this->missing('We can\'t find that check.');
        }

        return response()->json(['success' => true, 'data' => app(PaybillClaims::class)->present($claim)]);
    }

    /** GET /give/callback?reference= - back from Paystack's page: check it, then the thanks page. */
    public function callback(Request $request): RedirectResponse
    {
        $reference = (string) ($request->query('reference') ?: $request->query('trxref'));
        $gift = $reference !== '' ? Gift::where('reference', $reference)->first() : null;
        if ($gift) {
            try {
                $this->giving->complete($gift);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/give-thanks.php?ref='.urlencode($reference));
    }

    private function missing(string $message = 'We can\'t find that church - check the giving link.'): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 404);
    }
}
