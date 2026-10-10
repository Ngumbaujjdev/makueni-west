<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Models\Journal;
use App\Models\MpesaPayment;
use App\Models\MpesaRequest;
use App\Models\PaybillSettlement;
use App\Models\Remittance;
use App\Models\Territory;
use App\Services\Accounting\Chart;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\Paybill;
use App\Services\Payments\Daraja;
use App\Services\Settings\Settings;
use App\Support\AccountingAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The diocese paybill (docs/specs/accounting-spec.md, A8). The diocese's
 * paybill people (paybill.manage) see every payment, sort what waits, settle
 * the places and set it up; a place's book-readers see its own paybill
 * giving, what the diocese holds for it and its account numbers; its
 * treasurer asks a member's phone to pay.
 */
class PaybillController extends AccountingBase
{
    /** @var array<int, string> journal id => number, for the list */
    private array $numbers = [];

    public function __construct(private Paybill $paybill, private Settings $settings, private Chart $chart, private Ledger $ledger) {}

    /** GET /accounting/paybill - for the diocese: every payment; for a place: its own. */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $user = $request->user();
        $manage = AccountingAccess::can($user, $this->paybill->diocese(), 'paybill');
        $isDiocese = $place->territory_type->value === 'diocese';
        $q = MpesaPayment::with('place:id,name,code')->orderByDesc('paid_at')->orderByDesc('id')->limit(1000);
        if (! $isDiocese) {
            // A share paid to the diocese by M-Pesa (A6b) is a payment out, not giving in.
            $q->where('territory_id', $place->id)->where('status', 'posted')->whereNull('remittance_id');
        } else {
            // A church's own paybill (A10b) is its own business - unless it couldn't be posted and waits to be sorted.
            $q->where(fn ($w) => $w->whereNull('channel_id')->orWhere('status', 'to_sort'));
        }
        $payments = $q->get();
        $this->numbers = Journal::whereIn('id', $payments->pluck($isDiocese ? 'diocese_journal_id' : 'place_journal_id')->filter()->all())->pluck('number', 'id')->all();
        $out = [
            'place' => $this->placeInfo($place),
            'diocese' => $isDiocese,
            'can' => [
                'manage' => $manage && $isDiocese,
                'ask' => $manage || AccountingAccess::can($user, $place, 'receipt'),
                'own' => AccountingAccess::abilities($user, $place)['own'],
            ],
            'setup' => $this->setup(),
            'purposes' => collect(Paybill::PURPOSES)->map(fn ($p, $k) => ['key' => $k, 'label' => $p[0]])->values(),
            'account_numbers' => $isDiocese ? [] : array_values(array_map(fn ($k, $v) => $v + ['purpose' => $k], array_keys($this->paybill->accountNumbers($place)), $this->paybill->accountNumbers($place))),
            'payments' => $payments->map(fn ($p) => $this->present($p, $isDiocese))->values(),
            'to_sort' => $isDiocese ? $payments->where('status', 'to_sort')->count() : 0,
            'shortcode' => $this->settings->system('paybill.shortcode'),
        ];
        if (! $isDiocese && ($own = $this->paybill->mpesaChannel($place))) {
            $out['own'] = ['provider' => $own->provider, 'label' => \App\Models\PaymentChannel::PROVIDERS[$own->provider], 'number' => $own->account_number, 'till' => $own->account_name === 'Till',
                'accounts' => collect(Paybill::PURPOSES)->map(fn ($p, $k) => ['purpose' => $k, 'label' => $p[0], 'account' => Paybill::SUFFIX[$k]])->values()];
        }
        if ($isDiocese && $manage) {
            $out['places'] = Territory::whereIn('territory_type', ['church', 'region'])->whereNotNull('code')->orderBy('name')->get(['id', 'name', 'code', 'territory_type'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'code' => Paybill::code($t), 'level' => $t->territory_type->value])->values();
        }
        if (! $isDiocese) {
            $held = $this->chart->account('held_by_diocese');
            $out['held'] = round($this->ledger->balance($place, $held), 2);
            $out['settlements'] = Remittance::where('to_territory_id', $place->id)->where('kind', 'settlement')->orderByDesc('id')->limit(36)->get()
                ->map(fn ($r) => ['id' => $r->id, 'number' => $r->number, 'purpose' => $r->purpose, 'amount' => (float) $r->amount, 'status' => $r->status, 'status_label' => Remittance::STATUSES[$r->status], 'sent_on' => $r->sent_on?->toDateString()])->values();
        }

        return $this->ok($out);
    }

    /** POST /accounting/paybill/payments/{id}/sort {to: place|diocese|return, territory_id, purpose, note} */
    public function sort(Request $request, int $id): JsonResponse
    {
        $deny = $this->manager($request);
        if ($deny) {
            return $deny;
        }
        $p = MpesaPayment::find($id);
        if (! $p) {
            return $this->notFound('That payment isn\'t here.');
        }
        $data = $request->validate(['to' => ['required', 'in:place,diocese,return'], 'territory_id' => ['nullable', 'integer'], 'purpose' => ['nullable', 'string', 'max:3'], 'note' => ['nullable', 'string', 'max:255']]);
        $p = $this->paybill->sort($p, $request->user(), $data);

        return $this->ok($this->present($p->load('place:id,name,code'), true), match ($p->status) {
            'returned' => 'A voucher to return it is ready for approval.',
            default => $p->territory_id ? 'Sorted to '.$p->place?->name.'.' : 'Sorted.',
        });
    }

    /** POST /accounting/paybill/ask {phone, amount, purpose, territory_id?} - the M-Pesa prompt on a phone. */
    public function ask(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20'], 'amount' => ['required', 'numeric', 'min:1', 'max:250000'], 'purpose' => ['required', 'string', 'max:3'], 'for_id' => ['nullable', 'integer']],
            ['phone.required' => 'Whose phone?', 'amount.required' => 'How much?']);
        $user = $request->user();
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $manage = AccountingAccess::can($user, $this->paybill->diocese(), 'paybill');
        $for = $place;
        if (! empty($data['for_id'])) {
            $for = Territory::whereIn('territory_type', ['church', 'region', 'diocese'])->find((int) $data['for_id']);
            if (! $for || ! $manage) {
                return $this->forbidden('Only the diocese paybill people ask for another place.');
            }
        } elseif (! $manage && ! AccountingAccess::can($user, $place, 'receipt')) {
            return $this->forbidden('Whoever writes receipts here asks a phone to pay.');
        }
        $r = $this->paybill->ask($for, $user, $data['phone'], (float) $data['amount'], $data['purpose']);

        return $this->ok($this->presentRequest($r), 'Sent - the phone shows the M-Pesa prompt now.', 201);
    }

    /** GET /accounting/paybill/requests/{id} - for "waiting for the phone" to follow. */
    public function request(Request $request, int $id): JsonResponse
    {
        $r = MpesaRequest::find($id);
        if (! $r || ((int) $r->requested_by !== (int) $request->user()->id && ! $request->user()->hasGlobalAccess())) {
            return $this->notFound('That request isn\'t yours.');
        }

        return $this->ok($this->presentRequest($r));
    }

    /** GET /accounting/paybill/settlements?month= - what each place would get, and the month's settlements. */
    public function settlements(Request $request): JsonResponse
    {
        $deny = $this->manager($request);
        if ($deny) {
            return $deny;
        }
        $month = (string) $request->query('month', now()->subMonthNoOverflow()->format('Y-m'));
        $done = PaybillSettlement::with(['place:id,name,code', 'remittance.voucher:id,number,status'])->where('month', $month)->where('status', '!=', 'cancelled')->orderBy('id')->get();

        return $this->ok([
            'month' => $month,
            'preview' => $month < now()->format('Y-m') ? $this->paybill->preview($month) : [],
            'settled' => $done->map(fn ($s) => [
                'id' => $s->id, 'place' => $s->place ? ['id' => $s->place->id, 'name' => $s->place->name, 'code' => $s->place->code] : null,
                'held' => (float) $s->held, 'share' => (float) $s->share, 'net' => (float) $s->net, 'status' => $s->status,
                'remittance' => $s->remittance ? ['id' => $s->remittance->id, 'number' => $s->remittance->number, 'status' => $s->remittance->status, 'voucher' => $s->remittance->voucher ? ['id' => $s->remittance->voucher->id, 'number' => $s->remittance->voucher->number, 'status' => $s->remittance->voucher->status] : null] : null,
            ])->values(),
            'cash' => $this->chart->cashAccounts($this->paybill->diocese())->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'cash_kind' => $a->cash_kind])->values(),
        ]);
    }

    /** POST /accounting/paybill/settlements {month, places: [ids], pay_from_account_id} */
    public function settle(Request $request): JsonResponse
    {
        $deny = $this->manager($request, 'prepare');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['month' => ['required', 'string', 'size:7'], 'places' => ['required', 'array', 'min:1', 'max:200'], 'places.*' => ['integer'], 'pay_from_account_id' => ['required', 'integer']],
            ['places.required' => 'Pick the places to settle.']);
        $done = $this->paybill->settle($data['month'], $data['places'], $request->user(), (int) $data['pay_from_account_id']);
        $vouchers = collect($done)->filter(fn ($s) => $s->net > 0)->count();

        return $this->ok(['settled' => count($done)], count($done).' settled'.($vouchers ? " - {$vouchers} ".($vouchers === 1 ? 'voucher goes' : 'vouchers go').' for approval, then they are paid.' : '.'), 201);
    }

    public function cancelSettlement(Request $request, int $id): JsonResponse
    {
        $deny = $this->manager($request);
        if ($deny) {
            return $deny;
        }
        $s = PaybillSettlement::find($id);
        if (! $s) {
            return $this->notFound('That settlement isn\'t here.');
        }
        $this->paybill->cancel($s, $request->user());

        return $this->ok(null, 'Cancelled - the share netting is undone.');
    }

    /** POST /accounting/paybill/setup/register - send our addresses to Safaricom. */
    public function register(Request $request): JsonResponse
    {
        $deny = $this->manager($request);
        if ($deny) {
            return $deny;
        }
        try {
            $out = $this->paybill->register($request->user());
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['setup' => [$e->getMessage()]]);
        }

        return $this->ok(['safaricom' => $out['ResponseDescription'] ?? null, 'setup' => $this->setup()], 'Safaricom has our addresses: '.($out['ResponseDescription'] ?? 'registered').'.');
    }

    /** POST /accounting/paybill/setup/simulate {phone, amount, account} - sandbox only. */
    public function simulate(Request $request): JsonResponse
    {
        $deny = $this->manager($request);
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['phone' => ['required', 'string', 'max:20'], 'amount' => ['required', 'numeric', 'min:1', 'max:70000'], 'account' => ['required', 'string', 'max:20']]);
        try {
            $out = Daraja::diocese()->simulate($data['phone'], (float) $data['amount'], $data['account']);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['setup' => [$e->getMessage()]]);
        }

        return $this->ok($out, 'Test payment sent - it shows here when Safaricom calls back.');
    }

    // ------------------------------------------------------------ helpers

    /** Only the diocese paybill people, acting at the diocese. */
    private function manager(Request $request, string $also = 'paybill'): ?JsonResponse
    {
        $diocese = $this->paybill->diocese();
        $user = $request->user();
        if (! AccountingAccess::can($user, $diocese, 'paybill') || ! AccountingAccess::can($user, $diocese, $also)) {
            return $this->forbidden('Only the diocese finance officer and treasurer run the paybill.');
        }

        return null;
    }

    private function setup(): array
    {
        $s = $this->settings;
        $ready = (bool) ($s->system('paybill.shortcode') && $s->system('paybill.consumer_key') && $s->system('paybill.consumer_secret'));

        return [
            'ready' => $ready,
            'environment' => $s->system('paybill.environment') === 'production' ? 'production' : 'sandbox',
            'shortcode' => $s->system('paybill.shortcode'),
            'can_ask' => $ready && (bool) $s->system('paybill.passkey'),
            'callbacks' => (bool) $s->system('paybill.callback_key'),
            'last_payment' => MpesaPayment::orderByDesc('paid_at')->first()?->paid_at?->toIso8601String(),
        ];
    }

    private function present(MpesaPayment $p, bool $full): array
    {
        return [
            'id' => $p->id, 'trans_id' => $p->trans_id, 'kind' => $p->kind, 'amount' => (float) $p->amount, 'own' => (bool) $p->channel_id,
            'phone' => $p->phone, 'payer_name' => $p->payer_name, 'bill_ref' => $p->bill_ref, 'paid_at' => $p->paid_at?->toIso8601String(),
            'place' => $p->place ? ['id' => $p->place->id, 'name' => $p->place->name, 'code' => $p->place->code] : null,
            'purpose' => $p->purpose, 'purpose_label' => $p->remittance_id || $this->paybill->isShareRef($p->bill_ref) ? 'Diocese share' : (Paybill::PURPOSES[$p->purpose][0] ?? null),
            'status' => $p->status, 'status_label' => MpesaPayment::STATUSES[$p->status], 'note' => $p->note,
            'receipt' => ($j = ($full && ! $p->channel_id) ? $p->diocese_journal_id : $p->place_journal_id) ? ($this->numbers[$j] ?? Journal::find($j)?->number) : null,
        ];
    }

    private function presentRequest(MpesaRequest $r): array
    {
        return ['id' => $r->id, 'status' => $r->status, 'result' => $r->result, 'account_ref' => $r->account_ref, 'amount' => (float) $r->amount, 'phone' => $r->phone];
    }
}
