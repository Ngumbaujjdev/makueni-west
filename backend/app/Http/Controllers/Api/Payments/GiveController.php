<?php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use App\Models\Gift;
use App\Models\Journal;
use App\Models\Territory;
use App\Services\Accounting\Giving;
use App\Services\Accounting\Paybill;
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

    /** GET /give/status/{reference} - where a gift stands (a Paystack one still waiting is checked). */
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
        $place = Territory::find($gift->territory_id);

        return response()->json(['success' => true, 'data' => [
            'reference' => $gift->reference, 'status' => $gift->status, 'amount' => (float) $gift->amount,
            'purpose' => Paybill::PURPOSES[$gift->purpose][0] ?? null, 'place' => $place?->name, 'code' => $place ? Paybill::code($place) : null,
            'receipt' => $gift->status === 'paid' ? Journal::find($gift->journal_id)?->number : null,
            'result' => $gift->status === 'failed' ? $gift->result : null,
        ]]);
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
