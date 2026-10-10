<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Models\PaymentChannel;
use App\Models\PaystackSettlement;
use App\Models\Territory;
use App\Services\Accounting\Chart;
use App\Services\Accounting\Paybill;
use App\Services\Accounting\Payouts;
use App\Services\Payments\Paystack;
use App\Support\AccountingAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Getting paid (docs/specs/accounting-spec.md, A10c): a place's payouts and
 * how it gets paid (giving.read), its treasurer asking for its own Paystack
 * (accounts.manage there), and the diocese finance officer checking the
 * requests and seeing every place's payouts (gateways.manage).
 */
class PayoutController extends AccountingBase
{
    public function __construct(private Payouts $payouts, private Chart $chart) {}

    /** GET /accounting/giving/payouts?year= - the place's payouts, what's on the way, and how it gets paid. */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $year = (int) $request->query('year', now()->year);

        return $this->ok($this->payouts->list($place, $year) + [
            'place' => $this->placeInfo($place),
            'route' => $this->payouts->route($place),
            'can' => ['ask' => AccountingAccess::can($request->user(), $place, 'accounts') && $place->territory_type->value !== 'diocese'],
        ]);
    }

    /** GET /accounting/giving/payouts/{id} - a payout's gifts. */
    public function show(Request $request, int $id): JsonResponse
    {
        $row = PaystackSettlement::find($id);
        $place = $row ? Territory::find($row->territory_id) : null;
        if (! $row || ! $place || ! AccountingAccess::canRead($request->user(), $place)) {
            return $this->notFound('That payout isn\'t here.');
        }

        return $this->ok($this->payouts->detail($row));
    }

    /** GET /accounting/giving/payout-options - Paystack's banks and the place's bank accounts, for asking. */
    public function options(Request $request): JsonResponse
    {
        $place = $this->place($request, 'accounts');
        if ($place instanceof JsonResponse) {
            return $place;
        }

        return $this->ok([
            'ready' => Paystack::ready(),
            'banks' => collect(Paystack::ready() ? Paystack::diocese()->banks() : [])->map(fn ($name, $code) => ['code' => (string) $code, 'name' => $name])->values(),
            'accounts' => $this->chart->cashAccounts($place)->filter(fn ($a) => $a->cash_kind === 'bank' && (int) $a->territory_id === (int) $place->id)
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'account_number' => $a->account_number])->values(),
        ]);
    }

    /** POST /accounting/giving/payout-request {bank_code, account_number, account_name, settles_into_id} */
    public function ask(Request $request): JsonResponse
    {
        $place = $this->place($request, 'accounts');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate([
            'bank_code' => ['required', 'string', 'max:30'], 'account_number' => ['required', 'string', 'regex:/^[0-9]{5,20}$/'],
            'account_name' => ['required', 'string', 'max:150'], 'settles_into_id' => ['required', 'integer'],
        ], ['bank_code.required' => 'Pick the bank.', 'account_number.required' => 'Enter the account number.', 'account_number.regex' => 'Digits only.',
            'account_name.required' => 'Enter the account name exactly as the bank has it.', 'settles_into_id.required' => 'Pick which of our bank accounts it lands in.']);
        $ch = $this->payouts->ask($place, $request->user(), $data);

        return $this->ok($this->payouts->route($place), $ch->subaccount_code ? 'Sent - the diocese checks the change; card gifts keep going to the old account until then.' : 'Sent - the diocese finance officer checks it and switches it on.', 201);
    }

    /** DELETE /accounting/giving/payout-request - withdraw it while it waits. */
    public function withdraw(Request $request): JsonResponse
    {
        $place = $this->place($request, 'accounts');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $this->payouts->withdraw($place);

        return $this->ok($this->payouts->route($place), 'Withdrawn.');
    }

    /** POST /accounting/gateways/channels/{id}/review {decision: approve|return, note, switch_on} */
    public function review(Request $request, int $id): JsonResponse
    {
        $deny = $this->manager($request);
        if ($deny) {
            return $deny;
        }
        $ch = PaymentChannel::where('provider', 'paystack')->find($id);
        if (! $ch) {
            return $this->notFound('That request isn\'t here.');
        }
        $data = $request->validate(['decision' => ['required', 'in:approve,return'], 'note' => ['nullable', 'string', 'max:255'], 'switch_on' => ['nullable', 'boolean']]);
        $ch = $this->payouts->review($ch, $request->user(), $data['decision'], $data['note'] ?? null, (bool) ($data['switch_on'] ?? false));
        $name = Territory::find($ch->territory_id)?->name;

        return $this->ok(['id' => $ch->id, 'status' => $ch->status], $data['decision'] === 'return'
            ? "Sent back to {$name} with your note."
            : ($ch->status === 'active' ? "Approved - {$name}'s card gifts now settle to its own bank." : "Approved - {$name}'s subaccount is ready; switch it on when you're ready."));
    }

    /** GET /accounting/gateways/payouts?from&to - every place's payouts in the period. */
    public function overview(Request $request): JsonResponse
    {
        $deny = $this->manager($request);
        if ($deny) {
            return $deny;
        }
        $from = (string) $request->query('from', now()->startOfYear()->toDateString());
        $to = (string) $request->query('to', now()->toDateString());
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
            return response()->json(['success' => false, 'message' => 'Pick a period that starts before it ends.'], 422);
        }
        $requests = PaymentChannel::with('place:id,name,code,territory_type')->where('provider', 'paystack')->whereNotNull('request')->orderBy('requested_at')->get();

        return $this->ok([
            'from' => $from, 'to' => $to,
            'places' => $this->payouts->overview($from, $to),
            'requests' => $requests->map(fn ($c) => ['id' => $c->id, 'place' => ['id' => $c->territory_id, 'name' => $c->place?->name, 'code' => $c->place ? Paybill::code($c->place) : null],
                'status' => $c->status] + $this->payouts->presentRequest($c, true))->values(),
        ]);
    }

    private function manager(Request $request): ?JsonResponse
    {
        return AccountingAccess::can($request->user(), app(Paybill::class)->diocese(), 'gateways') ? null : $this->forbidden('Only the diocese finance officer sets up the gateways.');
    }
}
