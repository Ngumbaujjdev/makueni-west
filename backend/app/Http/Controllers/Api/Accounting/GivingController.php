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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Online giving in the books (docs/specs/accounting-spec.md, A10a): a place's
 * gifts and its giving link (giving.read), and the diocese's Gateways - each
 * church's Paystack subaccount, made on Paystack and switched on by the
 * diocese finance officer (gateways.manage) - and (A10b) its own paybill
 * through PayHero or its own Daraja app. Keys go in, never come back out.
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
        $own = PaymentChannel::where('territory_id', $place->id)->whereIn('provider', ['daraja', 'payhero'])->get();
        $route = app(Paybill::class)->mpesaChannel($place);

        return $this->ok([
            'place' => $this->placeInfo($place),
            'link' => rtrim((string) config('app.frontend_url'), '/').'/give.php?c='.Paybill::code($place),
            'code' => Paybill::code($place),
            'paystack' => ['ready' => Paystack::ready(), 'channel' => $channel ? $this->presentChannel($channel) : null],
            'mpesa' => [
                'route' => $route ? $route->provider : ($place->territory_type->value === 'diocese' || $this->settings()->system('paybill.shortcode') ? 'paybill' : null),
                'channels' => $own->map(fn ($c) => $this->presentChannel($c))->values(),
                'diocese_paybill' => $this->settings()->system('paybill.shortcode'),
                'accounts' => collect(Paybill::PURPOSES)->map(fn ($p, $k) => ['label' => $p[0], 'account' => Paybill::SUFFIX[$k]])->values(),
            ],
            'gifts' => $gifts->map(fn ($g) => [
                'id' => $g->id, 'reference' => $g->reference, 'amount' => (float) $g->amount, 'purpose' => $g->purpose, 'purpose_label' => Paybill::PURPOSES[$g->purpose][0] ?? $g->purpose,
                'giver' => $g->giver_name, 'phone' => $g->giver_phone, 'method' => $g->method, 'channel' => $g->channel, 'status' => $g->status, 'status_label' => Gift::STATUSES[$g->status],
                'fee' => (float) $g->fee, 'split' => (float) $g->split, 'net' => (float) $g->net, 'receipt' => $numbers[$g->journal_id] ?? null,
                'created_at' => $g->created_at?->toIso8601String(), 'paid_at' => $g->paid_at?->toIso8601String(), 'result' => in_array($g->status, ['failed', 'refunded'], true) || $g->disputed_at || (float) $g->refunded_amount > 0 ? $g->result : null,
                'disputed' => (bool) $g->disputed_at, 'refunded_amount' => (float) $g->refunded_amount,
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
        $all = PaymentChannel::get()->groupBy('territory_id');

        return $this->ok([
            'ready' => Paystack::ready(),
            'mode' => app(\App\Services\Settings\Settings::class)->system('giving.paystack_mode'),
            'places' => Territory::whereIn('territory_type', ['diocese', 'region', 'church'])->whereNotNull('code')->orderByRaw("FIELD(territory_type, 'diocese', 'region', 'church')")->orderBy('name')->get()
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'code' => Paybill::code($t), 'level' => $t->territory_type->value,
                    'channel' => ($c = ($all[$t->id] ?? collect())->firstWhere('provider', 'paystack')) ? $this->presentChannel($c) : null,
                    'mpesa' => ($all[$t->id] ?? collect())->whereIn('provider', ['daraja', 'payhero'])->sortBy('provider')->map(fn ($c) => $this->presentChannel($c))->values()])->values(),
            'settlements' => PaystackSettlement::orderByDesc('settled_on')->limit(50)->get()->map(fn ($s) => ['id' => $s->id, 'place' => Territory::find($s->territory_id)?->name, 'amount' => (float) $s->amount, 'settled_on' => $s->settled_on->toDateString()])->values(),
            'gifts_pending' => Gift::where('status', 'pending')->count(),
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
            'accounts' => $place ? $this->chart->cashAccounts($place)->filter(fn ($a) => $a->cash_kind === 'bank' && (int) $a->territory_id === (int) $place->id)->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'account_number' => $a->account_number])->values() : [],
            'mpesa_accounts' => $place ? $this->chart->cashAccounts($place)->filter(fn ($a) => $a->cash_kind === 'mpesa' && (int) $a->territory_id === (int) $place->id)->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'number' => $a->mpesa_number])->values() : [],
        ]);
    }

    /** POST /accounting/gateways/channels - make a church's Paystack subaccount (it stays off until switched on); for the diocese, where its payouts land. */
    public function storeChannel(Request $request): JsonResponse
    {
        $deny = $this->manager($request);
        if ($deny) {
            return $deny;
        }
        if (in_array($request->input('provider'), ['payhero', 'daraja'], true)) {
            return $this->storeMpesa($request);
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
        $existing = PaymentChannel::where('territory_id', $place->id)->where('provider', 'paystack')->first();
        if ($existing && ($existing->subaccount_code || $existing->status !== 'pending')) {
            throw ValidationException::withMessages(['territory_id' => ["{$place->name} already has its Paystack set up - change it instead."]]);
        }
        // A request from the church that was never made (A10c) is taken over by setting it up here.
        $existing?->delete();
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
        $mpesa = $channel->provider !== 'paystack';
        $data = $request->validate(['status' => ['nullable', 'in:active,off'], 'settles_into_id' => ['nullable', 'integer']] + ($mpesa ? $this->mpesaRules($channel->provider, false) : []));
        if (! empty($data['settles_into_id'])) {
            $into = AccountingAccount::where('territory_id', $channel->territory_id)->where('cash_kind', $mpesa ? 'mpesa' : 'bank')->find((int) $data['settles_into_id']);
            if (! $into) {
                throw ValidationException::withMessages(['settles_into_id' => [$mpesa ? 'Pick one of the place\'s M-Pesa accounts.' : 'Pick one of the place\'s bank accounts.']]);
            }
            $channel->settles_into_id = $into->id;
        }
        if ($mpesa) {
            $this->fillMpesa($channel, $data);
        }
        if (! empty($data['status'])) {
            if ($data['status'] === 'active' && ! $mpesa && ! $channel->subaccount_code && $channel->place?->territory_type->value !== 'diocese') {
                throw ValidationException::withMessages(['status' => ['Its subaccount isn\'t made yet - approve its request first.']]);
            }
            if ($data['status'] === 'active' && $mpesa && ! $channel->mpesaReady()) {
                throw ValidationException::withMessages(['status' => ['Fill in all its details first.']]);
            }
            $channel->status = $data['status'];
        }
        $channel->save();
        $name = $channel->place?->name ?? 'The church';

        return $this->ok($this->presentChannel($channel), match (true) {
            ! $mpesa => $channel->status === 'active' ? 'On - card gifts now go to its own Paystack.' : 'Off - card gifts go to the diocese and are settled monthly.',
            $channel->status === 'active' => "On - M-Pesa gifts to {$name} now go to its own ".($channel->account_name === 'Till' ? 'till' : 'paybill').'.',
            default => "Off - M-Pesa gifts to {$name} go through the diocese paybill.",
        });
    }

    /** POST /accounting/gateways/channels/{id}/register - send a church's own Daraja addresses to Safaricom. */
    public function registerChannel(Request $request, int $id): JsonResponse
    {
        $deny = $this->manager($request);
        if ($deny) {
            return $deny;
        }
        $channel = PaymentChannel::where('provider', 'daraja')->find($id);
        if (! $channel) {
            return $this->notFound('That Daraja app isn\'t here.');
        }
        try {
            app(Paybill::class)->registerChannel($channel);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['register' => [$e->getMessage()]]);
        }

        return $this->ok($this->presentChannel($channel->fresh()), 'Registered - Safaricom now tells us about every payment into '.$channel->account_number.'.');
    }

    /** A church's own paybill or till (A10b): PayHero, or its own Daraja app. Off until switched on. */
    private function storeMpesa(Request $request): JsonResponse
    {
        $provider = (string) $request->input('provider');
        $data = $request->validate(['territory_id' => ['required', 'integer'], 'settles_into_id' => ['nullable', 'integer']] + $this->mpesaRules($provider, true),
            ['number.required' => 'Enter the paybill or till number.', 'number.regex' => 'Digits only, e.g. 4123456.']);
        $place = Territory::whereIn('territory_type', ['region', 'church'])->find((int) $data['territory_id']);
        if (! $place) {
            return $this->notFound('Only a church or a region takes M-Pesa into its own paybill - the diocese has its paybill.');
        }
        if (PaymentChannel::where('territory_id', $place->id)->where('provider', $provider)->exists()) {
            throw ValidationException::withMessages(['territory_id' => ["{$place->name} already has its ".PaymentChannel::PROVIDERS[$provider].' set up - change it instead.']]);
        }
        if (! empty($data['settles_into_id'])) {
            $into = AccountingAccount::where('territory_id', $place->id)->where('cash_kind', 'mpesa')->find((int) $data['settles_into_id']);
            if (! $into) {
                throw ValidationException::withMessages(['settles_into_id' => ["Pick one of {$place->name}'s M-Pesa accounts."]]);
            }
        } else {
            $into = AccountingAccount::where('territory_id', $place->id)->where('cash_kind', 'mpesa')->where('mpesa_number', $data['number'])->first()
                ?? $this->chart->addPlaceAccount($place, 'mpesa', ['name' => (! empty($data['till']) ? 'Till ' : 'Paybill ').$data['number'], 'mpesa_number' => $data['number'],
                    'description' => 'Our own M-Pesa '.(! empty($data['till']) ? 'till' : 'paybill').' - gifts on the giving page land here'], $request->user()->id);
        }
        $channel = new PaymentChannel(['territory_id' => $place->id, 'provider' => $provider, 'status' => 'off', 'settles_into_id' => $into->id, 'created_by' => $request->user()->id, 'callback_key' => Str::random(40)]);
        $this->fillMpesa($channel, $data);
        $channel->save();

        return $this->ok($this->presentChannel($channel), "{$place->name}'s ".PaymentChannel::PROVIDERS[$provider].' is saved into '.$into->name.' - '.($provider === 'daraja' ? 'register the addresses, then switch it on.' : 'switch it on when you\'re ready.'), 201);
    }

    /** @return array<string, array<int, string>> */
    private function mpesaRules(string $provider, bool $new): array
    {
        $need = $new ? 'required' : 'nullable';
        $rules = ['number' => [$need, 'string', 'regex:/^[0-9]{5,10}$/'], 'till' => ['nullable', 'boolean']];

        return $rules + ($provider === 'daraja'
            ? ['environment' => [$need, 'in:sandbox,production'], 'consumer_key' => [$need, 'string', 'max:200'], 'consumer_secret' => [$need, 'string', 'max:200'], 'passkey' => [$need, 'string', 'max:200']]
            : ['username' => [$need, 'string', 'max:200'], 'password' => [$need, 'string', 'max:200'], 'channel_id' => [$need, 'integer', 'min:1']]);
    }

    /** Set what was sent; a key left blank is kept. */
    private function fillMpesa(PaymentChannel $channel, array $data): void
    {
        if (! empty($data['number'])) {
            $channel->account_number = $data['number'];
        }
        if (array_key_exists('till', $data) && $data['till'] !== null) {
            $channel->account_name = $data['till'] ? 'Till' : 'Paybill';
        }
        $channel->account_name ??= 'Paybill';
        $secrets = $channel->secrets();
        foreach ([...PaymentChannel::SECRETS[$channel->provider], 'environment'] as $k) {
            if (isset($data[$k]) && trim((string) $data[$k]) !== '') {
                $secrets[$k] = trim((string) $data[$k]);
            }
        }
        $channel->setSecrets($secrets);
    }

    private function settings(): \App\Services\Settings\Settings
    {
        return app(\App\Services\Settings\Settings::class);
    }

    private function manager(Request $request): ?JsonResponse
    {
        return AccountingAccess::can($request->user(), app(Paybill::class)->diocese(), 'gateways') ? null : $this->forbidden('Only the diocese finance officer sets up the gateways.');
    }

    private function presentChannel(PaymentChannel $c): array
    {
        $into = $c->settles_into_id ? AccountingAccount::find($c->settles_into_id) : null;

        $out = [
            'id' => $c->id, 'provider' => $c->provider, 'provider_label' => PaymentChannel::PROVIDERS[$c->provider], 'status' => $c->status, 'subaccount' => $c->subaccount_code,
            'bank' => $c->bank_name, 'account_number' => $c->account_number ? '•••• '.substr($c->account_number, -4) : null, 'account_name' => $c->account_name,
            'settles_into' => $into ? ['id' => $into->id, 'name' => $into->name] : null,
            'request' => (bool) $c->request, 'sent_back' => ! $c->request && $c->review_note ? $c->review_note : null,
        ];
        if ($c->provider !== 'paystack') {
            // A paybill or till number is public (givers type it); the keys are only ever said to be there.
            $s = $c->secrets();
            $out += ['number' => $c->account_number, 'till' => $c->account_name === 'Till', 'environment' => $s['environment'] ?? null, 'ready' => $c->mpesaReady(),
                'keys' => collect(PaymentChannel::SECRETS[$c->provider])->mapWithKeys(fn ($k) => [$k => filled($s[$k] ?? null)])];
        }

        return $out;
    }
}
