<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Models\Territory;
use App\Services\Accounting\Paybill;
use App\Services\Accounting\Transactions;
use App\Support\AccountingAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Transactions (docs/specs/accounting-spec.md, A10e): every attempt to pay
 * at a place - and, for a region or the diocese, the places below - with the
 * failed ones, each opening what happened when. Whoever writes receipts
 * there (or runs the diocese paybill) can ask the provider again.
 */
class TransactionController extends AccountingBase
{
    public function __construct(private Transactions $transactions) {}

    /** GET /accounting/transactions?from&to&status&method&source&q&place_id&page&per */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $f = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'status' => ['nullable', 'in:paid,pending,failed,refunded,to_sort,returned'],
            'method' => ['nullable', 'in:mpesa,card'], 'source' => ['nullable', 'in:gift,prompt,paybill,claim'], 'q' => ['nullable', 'string', 'max:100'],
            'place_id' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1'], 'per' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        return $this->ok($this->transactions->list($place, $f) + ['place' => $this->placeInfo($place), 'can' => ['check' => $this->canCheck($request, $place)]]);
    }

    /** GET /accounting/transactions/{source}/{id} */
    public function show(Request $request, string $source, int $id): JsonResponse
    {
        [$model, $deny] = $this->record($request, $source, $id);
        if ($deny) {
            return $deny;
        }

        return $this->ok($this->transactions->detail($source, $model) + ['can' => ['check' => $this->canCheck($request, Territory::find($model->territory_id))]]);
    }

    /** POST /accounting/transactions/{source}/{id}/check - ask Paystack or M-Pesa again. */
    public function check(Request $request, string $source, int $id): JsonResponse
    {
        [$model, $deny] = $this->record($request, $source, $id);
        if ($deny) {
            return $deny;
        }
        if (! $this->canCheck($request, Territory::find($model->territory_id))) {
            return $this->forbidden('Whoever writes receipts there checks a payment again.');
        }
        $this->transactions->check($source, $model);
        $d = $this->transactions->detail($source, $model->fresh());

        return $this->ok($d, match ($d['status']) {
            'paid' => 'Paid - it is in the books.',
            'failed', 'abandoned' => 'Not paid: '.($d['reason'] ?: 'the payment didn\'t go through.'),
            default => 'Still waiting - nothing is final yet. It is checked again on its own every few minutes.',
        });
    }

    /** POST /accounting/transactions/check-waiting {territory_id?, place_id?} */
    public function checkWaiting(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if (! $this->canCheck($request, $place)) {
            return $this->forbidden('Whoever writes receipts here checks payments again.');
        }
        $done = $this->transactions->checkWaiting($this->transactions->scope($place, $request->integer('place_id') ?: null));

        return $this->ok($done, $done['checked'] ? "Checked {$done['checked']} - {$done['paid']} paid, {$done['failed']} not paid, the rest still waiting." : 'Nothing has been waiting more than two minutes.');
    }

    /** POST /accounting/transactions/check-code {code, purpose, place_id?} - a treasurer checks an M-Pesa code with Safaricom (A10f). */
    public function checkCode(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate(['code' => ['required', 'string', 'max:20'], 'purpose' => ['required', 'string', 'max:8'], 'place_id' => ['nullable', 'integer']],
            ['code.required' => 'Enter the M-Pesa code.', 'purpose.required' => 'Pick what it was given for.']);
        $for = ! empty($data['place_id']) && in_array((int) $data['place_id'], $this->transactions->scope($place), true) ? Territory::findOrFail((int) $data['place_id']) : $place;
        if (! $this->canCheck($request, $for)) {
            return $this->forbidden('Whoever writes receipts there checks a payment.');
        }
        $claims = app(\App\Services\Accounting\PaybillClaims::class);
        $claim = $claims->claim($for, $data, $request->ip(), $request->user());

        return $this->ok($claims->present($claim), match ($claim->status) {
            'confirmed' => 'We have it - '.($claims->present($claim)['receipt'] ? 'receipt '.$claims->present($claim)['receipt'] : 'in the books').'.',
            'checking' => 'Asked Safaricom - the answer comes in a few seconds; it shows under Transactions.',
            default => (string) $claim->result,
        }, 201);
    }

    /** @return array{0: mixed, 1: ?JsonResponse} */
    private function record(Request $request, string $source, int $id): array
    {
        if (! in_array($source, Transactions::SOURCES, true)) {
            return [null, $this->notFound('That transaction isn\'t here.')];
        }
        [$model, $placeId] = $this->transactions->find($source, $id);
        $place = $placeId ? Territory::find($placeId) : app(Paybill::class)->diocese();
        if (! $model || ! $place || ! AccountingAccess::canRead($request->user(), $place)) {
            return [null, $this->notFound('That transaction isn\'t here.')];
        }

        return [$model, null];
    }

    private function canCheck(Request $request, ?Territory $place): bool
    {
        $user = $request->user();

        return $place && (AccountingAccess::can($user, $place, 'receipt') || AccountingAccess::can($user, app(Paybill::class)->diocese(), 'paybill'));
    }
}
