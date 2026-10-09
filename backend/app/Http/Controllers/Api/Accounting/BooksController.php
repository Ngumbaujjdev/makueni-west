<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingFund;
use App\Models\BudgetLine;
use App\Models\Journal;
use App\Models\PaymentVoucher;
use App\Models\Territory;
use App\Services\Accounting\Books;
use App\Services\Accounting\Chart;
use App\Services\Accounting\Documents;
use App\Services\Accounting\Ledger;
use App\Support\AccountingAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A place's books (docs/specs/accounting-spec.md): the overview, its
 * accounts and the chart, the cashbook, the documents, and writing
 * receipts, transfers and journal vouchers. Payment vouchers have their own
 * controller.
 */
class BooksController extends AccountingBase
{
    public function __construct(private Books $books, private Chart $chart, private Ledger $ledger, private Documents $docs) {}

    /** GET /accounting/overview - cash position, this month, by fund, the latest documents, vouchers waiting. */
    public function overview(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $today = CarbonImmutable::today();
        [$from, $to] = [$today->startOfMonth()->toDateString(), $today->toDateString()];
        [$pFrom, $pTo] = [$today->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->subMonthNoOverflow()->endOfMonth()->toDateString()];
        $vouchers = PaymentVoucher::where('territory_id', $place->id)->whereIn('status', ['prepared', 'authorised', 'rejected'])
            ->selectRaw('status, COUNT(*) AS n, SUM(amount) AS total')->groupBy('status')->get()->keyBy('status');

        return $this->ok([
            'place' => $this->placeInfo($place),
            'can' => AccountingAccess::abilities($request->user(), $place),
            'cash' => $this->books->cashPosition($place),
            'month' => ['from' => $from, 'to' => $to, 'label' => $today->format('F Y')] + $this->books->inOut($place, $from, $to),
            'last_month' => ['label' => $today->subMonthNoOverflow()->format('F Y')] + $this->books->inOut($place, $pFrom, $pTo),
            'year' => ['label' => (string) $today->year] + $this->books->inOut($place, $today->startOfYear()->toDateString(), $to),
            'monthly' => $this->books->monthly($place),
            'funds' => $this->books->byFund($place, $today->startOfYear()->toDateString(), $to),
            'top_income' => $this->books->byAccount($place, 'income', $today->startOfYear()->toDateString(), $to),
            'top_expense' => $this->books->byAccount($place, 'expense', $today->startOfYear()->toDateString(), $to),
            'vouchers' => collect(['prepared', 'authorised', 'rejected'])->mapWithKeys(fn ($s) => [$s => ['count' => (int) ($vouchers[$s]->n ?? 0), 'total' => (float) ($vouchers[$s]->total ?? 0)]]),
            'latest' => Journal::with(['poster', 'media'])->where('territory_id', $place->id)->orderByDesc('date')->orderByDesc('id')->limit(8)->get()
                ->map(fn ($j) => $this->books->presentJournal($j))->all(),
            'documents' => Journal::where('territory_id', $place->id)->count(),
            'collections' => $place->territory_type->value === 'church' ? $this->collections($place) : null,
        ]);
    }

    /** The church's last collection and this month's giving, for the Overview. */
    private function collections(Territory $place): array
    {
        $last = \App\Models\Collection::with('lines')->where('territory_id', $place->id)->where('status', 'posted')->orderByDesc('date')->orderByDesc('id')->first();
        $month = \App\Models\Collection::where('territory_id', $place->id)->where('status', 'posted')->where('date', '>=', now()->startOfMonth()->toDateString());

        return [
            'last' => $last ? ['id' => $last->id, 'date' => $last->date->toDateString(), 'title' => $last->title, 'total' => (float) $last->total, 'banked' => (bool) $last->banking_journal_id,
                'kinds' => $last->lines->groupBy('label')->map(fn ($g, $label) => ['label' => $label, 'amount' => round($g->sum(fn ($l) => (float) $l->cash_amount + (float) $l->mpesa_amount), 2)])->values()] : null,
            'month_total' => round((float) (clone $month)->sum('total'), 2),
            'month_count' => (clone $month)->count(),
            'waiting' => \App\Models\Collection::where('territory_id', $place->id)->where('status', 'counted')->count(),
            'unbanked' => \App\Services\Accounting\Collections::unbanked($place),
        ];
    }

    /** GET /accounting/places - our own books and, with "below", the regions and churches under us (for the picker). */
    public function places(Request $request): JsonResponse
    {
        $user = $request->user();
        $acting = \App\Support\PlaceAccess::acting($user);
        if (! $acting || ! AccountingAccess::canRead($user, $acting)) {
            return $this->forbidden('These books aren\'t yours to see.');
        }
        $places = [$this->placeInfo($acting) + ['own' => true, 'parent' => null]];
        if (AccountingAccess::abilities($user, $acting)['below']) {
            $ids = \App\Support\PlaceAccess::descendantIds($acting);
            $below = Territory::whereIn('id', $ids)->whereIn('territory_type', ['region', 'church'])->where('is_active', true)
                ->orderByRaw("FIELD(territory_type, 'region', 'church')")->orderBy('name')->get();
            $names = Territory::whereIn('id', $below->pluck('parent_territory_id'))->pluck('name', 'id');
            foreach ($below as $t) {
                $places[] = $this->placeInfo($t) + ['own' => false, 'parent' => $names[$t->parent_territory_id] ?? null];
            }
        }

        return $this->ok($places);
    }

    /** GET /accounting/options - what the forms pick from. */
    public function options(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $usable = $this->chart->usable($place);
        $pick = fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'cash_kind' => $a->cash_kind, 'own' => $a->territory_id !== null];
        $lines = BudgetLine::with('budgetCategory:id,slug')->where('is_active', true)
            ->forPlace($place->territory_type->value, $place->id)
            ->orderBy('display_order')->orderBy('name')->get();

        return $this->ok([
            'place' => $this->placeInfo($place),
            'can' => AccountingAccess::abilities($request->user(), $place),
            'today' => now()->toDateString(),
            'cash' => $usable->filter(fn ($a) => $a->cash_kind)->map($pick)->values(),
            'income' => $usable->where('type', 'income')->map($pick)->values(),
            'expense' => $usable->where('type', 'expense')->map($pick)->values(),
            'other' => $usable->filter(fn ($a) => ! $a->cash_kind && in_array($a->type, ['asset', 'liability', 'fund'], true))->map($pick)->values(),
            'funds' => AccountingFund::where('is_active', true)->orderBy('display_order')->get(['id', 'code', 'name', 'is_restricted', 'description']),
            'budget_lines' => $lines->map(fn ($l) => [
                'id' => $l->id, 'name' => $l->name, 'kind' => $l->budgetCategory?->slug,
                'account_id' => $l->account_id ?? $this->chart->forBudgetLine($l)->id,
            ])->values(),
            'methods' => collect(Journal::METHODS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'types' => collect(AccountingAccount::TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }

    /** GET /accounting/accounts - the chart with this place's accounts and balances. */
    public function accounts(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }

        return $this->ok([
            'place' => $this->placeInfo($place),
            'can' => AccountingAccess::abilities($request->user(), $place),
            'cash' => $this->books->cashPosition($place),
            'chart' => $this->books->chartFor($place)->values(),
            'funds' => AccountingFund::orderBy('display_order')->get(['id', 'code', 'name', 'is_restricted', 'description', 'is_active']),
        ]);
    }

    /** POST /accounting/accounts - one of our own bank or M-Pesa accounts. */
    public function storeAccount(Request $request): JsonResponse
    {
        $place = $this->place($request, 'accounts');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate($this->accountRules() + ['cash_kind' => ['required', 'in:bank,mpesa']]);
        $this->assertNameFree($place, $data['name']);
        $account = $this->chart->addPlaceAccount($place, $data['cash_kind'], $data, $request->user()->id);

        return $this->ok($this->books->presentAccount($account), "{$account->name} added.", 201);
    }

    /** PUT /accounting/accounts/{id} - rename, bank details, switch on or off (our own only). */
    public function updateAccount(Request $request, int $id): JsonResponse
    {
        $place = $this->place($request, 'accounts');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $account = AccountingAccount::where('territory_id', $place->id)->find($id);
        if (! $account) {
            return $this->notFound('That isn\'t one of your own accounts. The standard ones are the diocese\'s.');
        }
        $data = $request->validate($this->accountRules() + ['is_active' => ['sometimes', 'boolean']]);
        $this->assertNameFree($place, $data['name'], $account->id);
        if (array_key_exists('is_active', $data) && ! $data['is_active'] && abs($this->ledger->balance($place, $account)) >= 0.005) {
            throw ValidationException::withMessages(['is_active' => ["{$account->name} still holds KES ".number_format($this->ledger->balance($place, $account), 2).' - move it out first.']]);
        }
        $account->update($data);

        return $this->ok($this->books->presentAccount($account->fresh()), 'Saved.');
    }

    /** POST /accounting/chart - a new standard account (the diocese). */
    public function storeChart(Request $request): JsonResponse
    {
        $place = $this->place($request, 'chart');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[0-9][0-9-]*$/', Rule::unique('accounting_accounts', 'code')->whereNull('territory_id')],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', 'in:asset,liability,fund,income,expense'],
            'description' => ['nullable', 'string', 'max:255'],
        ], ['code.unique' => 'There\'s already an account with that code.', 'code.regex' => 'Use numbers, like 5240.']);
        $first = ['asset' => '1', 'liability' => '2', 'fund' => '3', 'income' => '4', 'expense' => '5'][$data['type']];
        if ($data['code'][0] !== $first) {
            throw ValidationException::withMessages(['code' => [AccountingAccount::TYPES[$data['type']]." codes start with {$first}."]]);
        }
        $account = AccountingAccount::create($data + ['territory_id' => null, 'is_active' => true, 'display_order' => 9000, 'created_by' => $request->user()->id]);

        return $this->ok($this->books->presentAccount($account), "{$account->code} {$account->name} added to the chart.", 201);
    }

    /** PUT /accounting/chart/{id} - rename or switch a standard account on or off (the diocese). */
    public function updateChart(Request $request, int $id): JsonResponse
    {
        $place = $this->place($request, 'chart');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $account = AccountingAccount::whereNull('territory_id')->find($id);
        if (! $account) {
            return $this->notFound('That account isn\'t in the standard chart.');
        }
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:255'], 'is_active' => ['sometimes', 'boolean']]);
        if (($data['is_active'] ?? true) === false && $account->system_key) {
            throw ValidationException::withMessages(['is_active' => ["{$account->name} is one the books need - it can be renamed but not switched off."]]);
        }
        $account->update($data);

        return $this->ok($this->books->presentAccount($account->fresh()), 'Saved.');
    }

    /** GET /accounting/cashbook?account_id=&from=&to= */
    public function cashbook(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate(['account_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $accounts = $this->books->cashPosition($place);
        $id = (int) ($data['account_id'] ?? ($accounts[0]['id'] ?? 0));
        $account = AccountingAccount::usableBy($place->id)->whereNotNull('cash_kind')->where('is_header', false)->find($id);
        if (! $account) {
            return $this->notFound('Pick one of your cash, bank or M-Pesa accounts.');
        }
        $to = $data['to'] ?? now()->toDateString();
        $from = $data['from'] ?? CarbonImmutable::parse($to)->startOfMonth()->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return $this->ok($this->books->cashbook($place, $account, $from, $to) + ['accounts' => $accounts, 'place' => $this->placeInfo($place), 'can' => AccountingAccess::abilities($request->user(), $place)]);
    }

    /** GET /accounting/journals?type=&from=&to=&q=&account_id= - the documents, newest first. */
    public function journals(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate([
            'type' => ['nullable', 'in:receipt,payment,transfer,journal,reversal,petty_cash'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'q' => ['nullable', 'string', 'max:100'], 'account_id' => ['nullable', 'integer'],
        ]);
        $to = $data['to'] ?? now()->toDateString();
        $from = $data['from'] ?? CarbonImmutable::parse($to)->startOfYear()->toDateString();
        $rows = Journal::with(['poster', 'media'])->where('territory_id', $place->id)
            ->whereBetween('date', [$from, $to])
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('doc_type', $t))
            ->when($data['account_id'] ?? null, fn ($q, $a) => $q->whereHas('lines', fn ($l) => $l->where('account_id', $a)))
            ->when(trim((string) ($data['q'] ?? '')) !== '', function ($q) use ($data) {
                $like = '%'.trim($data['q']).'%';
                $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('party_name', 'like', $like)->orWhere('narration', 'like', $like)->orWhere('reference', 'like', $like));
            })
            ->orderByDesc('date')->orderByDesc('id')->limit(1000)->get();
        $accounts = DB::table('journal_lines as l')->join('accounting_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('l.journal_id', $rows->pluck('id'))->whereNull('a.cash_kind')
            ->select('l.journal_id', 'a.name')->get()->groupBy('journal_id');
        $cash = DB::table('journal_lines as l')->join('accounting_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('l.journal_id', $rows->pluck('id'))->whereNotNull('a.cash_kind')
            ->select('l.journal_id', 'a.name')->get()->groupBy('journal_id');

        return $this->ok([
            'from' => $from, 'to' => $to,
            'place' => $this->placeInfo($place),
            'can' => AccountingAccess::abilities($request->user(), $place),
            'items' => $rows->map(fn ($j) => $this->books->presentJournal($j) + [
                'accounts' => ($accounts[$j->id] ?? collect())->pluck('name')->unique()->values()->all(),
                'cash_accounts' => ($cash[$j->id] ?? collect())->pluck('name')->unique()->values()->all(),
            ])->values(),
        ]);
    }

    /** GET /accounting/journals/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        [$journal, $place, $deny] = $this->journal($request, $id);
        if ($deny) {
            return $deny;
        }
        $can = AccountingAccess::abilities($request->user(), $place);

        return $this->ok($this->books->presentJournal($journal, true) + [
            'place' => $this->placeInfo($place),
            'can' => $can + ['reverse' => $this->mayReverse($request, $journal, $place)],
        ]);
    }

    /** POST /accounting/receipts */
    public function receipt(Request $request): JsonResponse
    {
        $place = $this->place($request, 'receipt');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate([
            'date' => ['required', 'date'],
            'account_id' => ['required', 'integer'],
            'party_name' => ['required', 'string', 'max:150'],
            'party_phone' => ['nullable', 'string', 'max:30'],
            'method' => self::METHOD_RULE,
            'reference' => ['nullable', 'string', 'max:100'],
            'narration' => ['nullable', 'string', 'max:255'],
        ] + $this->lineRules(), ['party_name.required' => 'Who was the money received from?', 'lines.required' => 'Add what the money was for.']);
        $journal = $this->docs->receipt($place, $request->user(), $data);

        return $this->ok($this->books->presentJournal($journal->fresh(), true), "Receipt {$journal->number} written.", 201);
    }

    /** POST /accounting/transfers */
    public function transfer(Request $request): JsonResponse
    {
        $place = $this->place($request, 'receipt');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate([
            'date' => ['required', 'date'],
            'from_account_id' => ['required', 'integer'],
            'to_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:100'],
            'narration' => ['nullable', 'string', 'max:255'],
        ]);
        $journal = $this->docs->transfer($place, $request->user(), $data);

        return $this->ok($this->books->presentJournal($journal->fresh(), true), "Transfer {$journal->number} recorded.", 201);
    }

    /** POST /accounting/journal-vouchers */
    public function journalVoucher(Request $request): JsonResponse
    {
        $place = $this->place($request, 'journal');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate([
            'date' => ['required', 'date'],
            'narration' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:40'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.fund_id' => ['nullable', 'integer'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ], ['narration.required' => 'Say what the journal is for.']);
        $journal = $this->docs->journalVoucher($place, $request->user(), $data);

        return $this->ok($this->books->presentJournal($journal->fresh(), true), "Journal {$journal->number} posted.", 201);
    }

    /** POST /accounting/journals/{id}/reverse {reason, date?} */
    public function reverse(Request $request, int $id): JsonResponse
    {
        [$journal, $place, $deny] = $this->journal($request, $id);
        if ($deny) {
            return $deny;
        }
        if (! $this->mayReverse($request, $journal, $place)) {
            return $this->forbidden('Your role can\'t reverse this document.');
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:255'], 'date' => ['nullable', 'date']], ['reason.required' => 'Say why it is being reversed.']);
        if (isset($data['date'])) {
            $this->docs->notFuture($data['date']);
        }
        $reversal = $this->docs->reverse($journal, $request->user(), $data['reason'], $data['date'] ?? null);

        return $this->ok($this->books->presentJournal($reversal->fresh(), true), "{$journal->number} reversed by {$reversal->number}.");
    }

    /** GET /accounting/trial-balance?date= */
    public function trialBalance(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $date = $request->validate(['date' => ['nullable', 'date']])['date'] ?? now()->toDateString();

        return $this->ok(['date' => $date, 'place' => $this->placeInfo($place)] + $this->ledger->trialBalance($place, $date));
    }

    /** POST /accounting/journals/{id}/attachments {file} */
    public function addAttachment(Request $request, int $id): JsonResponse
    {
        [$journal, $place, $deny] = $this->journal($request, $id);
        if ($deny) {
            return $deny;
        }
        if (! $this->mayWrite($request, $place)) {
            return $this->forbidden('Your role can\'t add files here.');
        }
        $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120']], ['file.max' => 'The file must be 5 MB or smaller.', 'file.mimes' => 'Attach a photo (JPG, PNG, WebP) or a PDF.']);
        if ($journal->getMedia('attachments')->count() >= Journal::MAX_ATTACHMENTS) {
            throw ValidationException::withMessages(['file' => ['A document can hold '.Journal::MAX_ATTACHMENTS.' files.']]);
        }
        $file = $request->file('file');
        $journal->addMedia($file)->usingFileName(Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin'))
            ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Receipt')
            ->withCustomProperties(['added_by' => $request->user()->id])->toMediaCollection('attachments');

        return $this->ok($this->books->presentJournal($journal->fresh(), true), 'Attached.', 201);
    }

    /** GET /accounting/journals/{id}/attachments/{media} */
    public function showAttachment(Request $request, int $id, int $media): Response|JsonResponse
    {
        [$journal, , $deny] = $this->journal($request, $id);
        if ($deny) {
            return $deny;
        }
        $file = $journal->getMedia('attachments')->firstWhere('id', $media);
        if (! $file) {
            return $this->notFound('That file isn\'t on this document.');
        }

        return response()->file($file->getPath(), ['Content-Type' => $file->mime_type, 'Content-Disposition' => 'inline; filename="'.addslashes($file->file_name).'"']);
    }

    /** DELETE /accounting/journals/{id}/attachments/{media} */
    public function removeAttachment(Request $request, int $id, int $media): JsonResponse
    {
        [$journal, $place, $deny] = $this->journal($request, $id);
        if ($deny) {
            return $deny;
        }
        if (! $this->mayWrite($request, $place)) {
            return $this->forbidden('Your role can\'t remove files here.');
        }
        $file = $journal->getMedia('attachments')->firstWhere('id', $media);
        if (! $file) {
            return $this->notFound('That file isn\'t on this document.');
        }
        $file->delete();

        return $this->ok($this->books->presentJournal($journal->fresh(), true), 'Removed.');
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0: ?Journal, 1: ?Territory, 2: ?JsonResponse} */
    private function journal(Request $request, int $id): array
    {
        $journal = Journal::find($id);
        if (! $journal) {
            return [null, null, $this->notFound('That document isn\'t in the books.')];
        }
        $place = Territory::find($journal->territory_id);
        if (! $place || ! AccountingAccess::canRead($request->user(), $place)) {
            return [null, null, $this->notFound('That document isn\'t in the books.')];
        }

        return [$journal, $place, null];
    }

    /** Whoever keeps the books reverses anything; a treasurer their receipts and transfers. */
    private function mayReverse(Request $request, Journal $journal, Territory $place): bool
    {
        if ($journal->status !== 'posted' || $journal->doc_type === 'reversal' || in_array($journal->source_type, ['budget_entry', 'payment_voucher', 'cash_count', 'collection'], true)) {
            return false;
        }
        $u = $request->user();

        return AccountingAccess::can($u, $place, 'journal')
            || (in_array($journal->doc_type, ['receipt', 'transfer'], true) && AccountingAccess::can($u, $place, 'receipt'))
            || ($journal->doc_type === 'petty_cash' && AccountingAccess::can($u, $place, 'petty'));
    }

    private function mayWrite(Request $request, Territory $place): bool
    {
        foreach (['receipt', 'prepare', 'pay', 'journal'] as $ability) {
            if (AccountingAccess::can($request->user(), $place, $ability)) {
                return true;
            }
        }

        return false;
    }

    private function accountRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'branch' => ['nullable', 'string', 'max:100'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'mpesa_number' => ['nullable', 'string', 'max:30'],
        ];
    }

    private function assertNameFree(Territory $place, string $name, ?int $ignore = null): void
    {
        $taken = AccountingAccount::usableBy($place->id)->whereNotNull('cash_kind')->where('name', trim($name))
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => ['You already have an account with that name.']]);
        }
    }
}
