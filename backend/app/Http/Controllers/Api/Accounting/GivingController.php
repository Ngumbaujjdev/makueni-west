<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Models\AccountingAccount;
use App\Models\Gift;
use App\Models\Journal;
use App\Models\PaymentChannel;
use App\Models\PaystackSettlement;
use App\Models\Territory;
use App\Services\Accounting\Chart;
use App\Services\Accounting\Giving;
use App\Services\Accounting\Paybill;
use App\Services\Payments\Paystack;
use App\Support\AccountingAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Online giving in the books (docs/specs/accounting-spec.md, A10a): a place's
 * gifts and its giving link (giving.read), and the diocese's Gateways - each
 * church's Paystack subaccount, made on Paystack and switched on by the
 * diocese finance officer (gateways.manage).
 */
class GivingController extends AccountingBase
{
    public function __construct(private Giving $giving, private Chart $chart) {}

    /** GET /accounting/giving - the place's gifts, its link, how its Paystack is set up. */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $gifts = Gift::where('territory_id', $place->id)->orderByDesc('id')->limit(1000)->get();
        $numbers = Journal::whereIn('id', $gifts->pluck('journal_id')->filter())->pluck('number', 'id');
        $channel = PaymentChannel::where('territory_id', $place->id)->where('provider', 'paystack')->first();

        return $this->ok([
            'place' => $this->placeInfo($place),
            'link' => rtrim((string) config('app.frontend_url'), '/').'/give.php?c='.Paybill::code($place),
            'code' => Paybill::code($place),
            'paystack' => ['ready' => Paystack::ready(), 'channel' => $channel ? $this->presentChannel($channel) : null],
            'gifts' => $gifts->map(fn ($g) => [
                'id' => $g->id, 'reference' => $g->reference, 'amount' => (float) $g->amount, 'purpose' => $g->purpose, 'purpose_label' => Paybill::PURPOSES[$g->purpose][0] ?? $g->purpose,
                'giver' => $g->giver_name, 'phone' => $g->giver_phone, 'method' => $g->method, 'channel' => $g->channel, 'status' => $g->status, 'status_label' => Gift::STATUSES[$g->status],
                'fee' => (float) $g->fee, 'split' => (float) $g->split, 'net' => (float) $g->net, 'receipt' => $numbers[$g->journal_id] ?? null,
                'created_at' => $g->created_at?->toIso8601String(), 'paid_at' => $g->paid_at?->toIso8601String(), 'result' => $g->status === 'failed' ? $g->result : null,
            ])->values(),
        ]);
    }

    /** GET /accounting/gateways - every church's Paystack set-up, and the latest payouts. */
    public function gateways(Request $request): JsonResponse
    {
        $diocese = app(Paybill::class)->diocese();
        if (! AccountingAccess::can($request->user(), $diocese, 'gateways')) {
            return $this->forbidden('Only the diocese finance officer sets up the gateways.');
        }
        $channels = PaymentChannel::with('place:id,name,code,territory_type')->where('provider', 'paystack')->get()->keyBy('territory_id');

        return $this->ok([
            'ready' => Paystack::ready(),
            'mode' => app(\App\Services\Settings\Settings::class)->system('giving.paystack_mode'),
            'places' => Territory::whereIn('territory_type', ['diocese', 'region', 'church'])->whereNotNull('code')->orderByRaw("FIELD(territory_type, 'diocese', 'region', 'church')")->orderBy('name')->get()
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'code' => Paybill::code($t), 'level' => $t->territory_type->value, 'channel' => isset($channels[$t->id]) ? $this->presentChannel($channels[$t->id]) : null])->values(),
            'settlements' => PaystackSettlement::orderByDesc('settled_on')->limit(50)->get()->map(fn ($s) => ['id' => $s->id, 'place' => Territory::find($s->territory_id)?->name, 'amount' => (float) $s->amount, 'settled_on' => $s->settled_on->toDateString()])->values(),
            'gifts_pending' => Gift::where('status', 'pending')->where('method', 'paystack')->count(),
        ]);
    }

    /** GET /accounting/gateways/banks - Paystack's Kenyan banks; GET ...?place= - that place's bank accounts to settle into. */
    public function banks(Request $request): JsonResponse
    {
        $deny = $this->manager($request);
        if ($deny) {
            return $deny;
        }
        $place = Territory::find((int) $request->query('place'));

        return $this->ok([
            'banks' => collect(Paystack::ready() ? Paystack::diocese()->banks() : [])->map(fn ($name, $code) => ['code' => (string) $code, 'name' => $name])->values(),
            'accounts' => $place ? $this->chart->cashAccounts($place)->filter(fn ($a) => $a->cash_kind === 'bank')->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'account_number' => $a->account_number])->values() : [],
        ]);
    }

    /** POST /accounting/gateways/channels - make a church's Paystack subaccount (it stays off until switched on); for the diocese, where its payouts land. */
    public function storeChannel(Request $request): JsonResponse
    {
        $deny = $this->manager($request);
        if ($deny) {
            return $deny;
        }
        $data = $request->validate([
            'territory_id' => ['required', 'integer'],
            'bank_code' => ['nullable', 'string', 'max:30'],
            'account_number' => ['nullable', 'string', 'max:40', 'regex:/^[0-9]{5,20}$/'],
            'account_name' => ['nullable', 'string', 'max:150'],
            'settles_into_id' => ['required', 'integer'],
        ], ['settles_into_id.required' => 'Pick which of its bank accounts Paystack pays into.', 'account_number.regex' => 'Digits only.']);
        $place = Territory::whereIn('territory_type', ['diocese', 'region', 'church'])->find((int) $data['territory_id']);
        if (! $place) {
            return $this->notFound('That place isn\'t here.');
        }
        $into = AccountingAccount::where('territory_id', $place->id)->where('cash_kind', 'bank')->find((int) $data['settles_into_id']);
        if (! $into) {
            throw ValidationException::withMessages(['settles_into_id' => ["Pick one of {$place->name}'s bank accounts - add it under Cash & bank first."]]);
        }
        if (PaymentChannel::where('territory_id', $place->id)->where('provider', 'paystack')->exists()) {
            throw ValidationException::withMessages(['territory_id' => ["{$place->name} already has its Paystack set up - change it instead."]]);
        }
        $fields = ['territory_id' => $place->id, 'provider' => 'paystack', 'settles_into_id' => $into->id, 'created_by' => $request->user()->id];
        if ($place->territory_type->value === 'diocese') {
            $channel = PaymentChannel::create($fields + ['status' => 'active', 'bank_name' => $into->bank_name, 'account_number' => $into->account_number, 'account_name' => $into->name]);

            return $this->ok($this->presentChannel($channel), 'Saved - the diocese\'s Paystack payouts are recorded into '.$into->name.'.', 201);
        }
        foreach (['bank_code' => 'Pick the bank.', 'account_number' => 'Enter the account number.', 'account_name' => 'Enter the account name exactly as the bank has it.'] as $k => $msg) {
            if (empty($data[$k])) {
                throw ValidationException::withMessages([$k => [$msg]]);
            }
        }
        $banks = Paystack::diocese()->banks();
        try {
            $sub = Paystack::diocese()->createSubaccount($place->name, $data['bank_code'], $data['account_number'], 'Giving to '.$place->name.' ('.Paybill::code($place).')');
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['account_number' => [$e->getMessage()]]);
        }
        $channel = PaymentChannel::create($fields + ['status' => 'off', 'subaccount_code' => $sub['subaccount_code'] ?? null, 'bank_code' => $data['bank_code'],
            'bank_name' => $banks[$data['bank_code']] ?? null, 'account_number' => $data['account_number'], 'account_name' => $data['account_name']]);

        return $this->ok($this->presentChannel($channel), "{$place->name}'s Paystack subaccount is made - switch it on when you're ready.", 201);
    }

    /** PUT /accounting/gateways/channels/{id} {status: active|off, settles_into_id?} */
    public function updateChannel(Request $request, int $id): JsonResponse
    {
        $deny = $this->manager($request);
        if ($deny) {
            return $deny;
        }
        $channel = PaymentChannel::find($id);
        if (! $channel) {
            return $this->notFound('That gateway isn\'t here.');
        }
        $data = $request->validate(['status' => ['nullable', 'in:active,off'], 'settles_into_id' => ['nullable', 'integer']]);
        if (! empty($data['settles_into_id'])) {
            $into = AccountingAccount::where('territory_id', $channel->territory_id)->where('cash_kind', 'bank')->find((int) $data['settles_into_id']);
            if (! $into) {
                throw ValidationException::withMessages(['settles_into_id' => ['Pick one of the place\'s bank accounts.']]);
            }
            $channel->settles_into_id = $into->id;
        }
        if (! empty($data['status'])) {
            $channel->status = $data['status'];
        }
        $channel->save();

        return $this->ok($this->presentChannel($channel), $channel->status === 'active' ? 'On - card gifts now go to its own Paystack.' : 'Off - card gifts go to the diocese and are settled monthly.');
    }

    private function manager(Request $request): ?JsonResponse
    {
        return AccountingAccess::can($request->user(), app(Paybill::class)->diocese(), 'gateways') ? null : $this->forbidden('Only the diocese finance officer sets up the gateways.');
    }

    private function presentChannel(PaymentChannel $c): array
    {
        $into = $c->settles_into_id ? AccountingAccount::find($c->settles_into_id) : null;

        return [
            'id' => $c->id, 'provider' => $c->provider, 'status' => $c->status, 'subaccount' => $c->subaccount_code,
            'bank' => $c->bank_name, 'account_number' => $c->account_number ? '•••• '.substr($c->account_number, -4) : null, 'account_name' => $c->account_name,
            'settles_into' => $into ? ['id' => $into->id, 'name' => $into->name] : null,
        ];
    }
}
