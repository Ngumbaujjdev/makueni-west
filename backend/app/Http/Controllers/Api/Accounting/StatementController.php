<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Services\Accounting\Statements;
use App\Services\Accounting\Years;
use App\Support\AccountingAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The financial statements and the year-end close (docs/specs/accounting-spec.md,
 * A9). Any statement for whoever reads the place's books; consolidated (every
 * place below added in, what moved between them taken out) for whoever reads
 * the books below. The place's own treasurer or finance officer closes a
 * year; the level above reopens it, with a reason.
 */
class StatementController extends AccountingBase
{
    public const KINDS = ['ie', 'position', 'receipts-payments', 'funds', 'trial-balance'];

    public function __construct(private Statements $statements, private Years $years) {}

    /** GET /accounting/statements/{kind}?from&to | at &consolidated=1 &before_close=1 */
    public function show(Request $request, string $kind): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'at' => ['nullable', 'date_format:Y-m-d'],
            'consolidated' => ['nullable', 'boolean'],
            'before_close' => ['nullable', 'boolean'],
        ], ['to.after_or_equal' => 'The end comes after the start.']);
        $consolidated = $request->boolean('consolidated');
        if ($consolidated && ! AccountingAccess::canConsolidate($request->user(), $place)) {
            return $this->forbidden($place->territory_type->value === 'church' ? 'A church has no places below to add in.' : 'Adding in the places below needs you to read their books.');
        }
        $today = CarbonImmutable::today()->toDateString();
        $to = $data['to'] ?? $today;
        $from = $data['from'] ?? CarbonImmutable::parse($to)->startOfYear()->toDateString();
        $at = $data['at'] ?? ($data['to'] ?? $today);

        $statement = match ($kind) {
            'ie' => $this->statements->incomeExpenditure($place, $from, $to, $consolidated),
            'position' => $this->statements->position($place, $at, $consolidated),
            'receipts-payments' => $this->statements->receiptsPayments($place, $from, $to, $consolidated),
            'funds' => $this->statements->changesInFunds($place, $from, $to, $consolidated),
            'trial-balance' => $this->statements->trialBalance($place, $at, $consolidated, $request->boolean('before_close')),
        };

        return $this->ok($statement + [
            'can' => ['consolidate' => AccountingAccess::canConsolidate($request->user(), $place)],
            'year_closed' => $this->years->isClosed($place, (int) substr($statement['to'] ?? $statement['at'], 0, 4)),
        ]);
    }

    /** GET /accounting/years - the years with postings, each closed or what stops it closing. */
    public function years(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }

        return $this->ok([
            'place' => $this->placeInfo($place),
            'can' => ['close' => AccountingAccess::can($request->user(), $place, 'close'), 'reopen' => AccountingAccess::canReopen($request->user(), $place)],
            'years' => $this->years->list($place),
        ]);
    }

    /** POST /accounting/years/{year}/close */
    public function close(Request $request, int $year): JsonResponse
    {
        $place = $this->place($request, 'close');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $row = $this->years->close($place, $request->user(), $year);

        return $this->ok(['year' => $row->year, 'status' => $row->status, 'surplus' => (float) $row->surplus, 'closing_journal_id' => $row->closing_journal_id],
            "{$year} is closed - its income and spending are now in the funds.");
    }

    /** POST /accounting/years/{year}/reopen {reason} - for a place below, ?territory_id= */
    public function reopen(Request $request, int $year): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if (! AccountingAccess::canReopen($request->user(), $place)) {
            return $this->forbidden('A closed year is reopened by the level above, with a reason.');
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say why it is being reopened.']);
        $row = $this->years->reopen($place, $request->user(), $year, $data['reason']);

        return $this->ok(['year' => $row->year, 'status' => $row->status], "{$year} is open again - its months stay closed until reopened one by one.");
    }
}
