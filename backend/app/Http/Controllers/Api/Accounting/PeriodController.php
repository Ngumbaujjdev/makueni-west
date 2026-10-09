<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Services\Accounting\Periods;
use App\Support\AccountingAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Month-end close (docs/specs/accounting-spec.md, A2): the months of a year
 * with their checklists; close (the place's own treasurer or finance
 * officer); reopen (the level above, with a reason - the one write allowed
 * into a place below).
 */
class PeriodController extends AccountingBase
{
    public function __construct(private Periods $periods) {}

    /** GET /accounting/periods?year= */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $year = (int) ($request->query('year') ?: now()->year);
        if ($year < 2000 || $year > now()->year) {
            $year = now()->year;
        }

        return $this->ok([
            'place' => $this->placeInfo($place),
            'can' => AccountingAccess::abilities($request->user(), $place),
            'year' => $year,
            'months' => $this->periods->year($place, $year),
        ]);
    }

    /** POST /accounting/periods/close {year, month} */
    public function close(Request $request): JsonResponse
    {
        $place = $this->place($request, 'close');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate(['year' => ['required', 'integer', 'min:2000'], 'month' => ['required', 'integer', 'between:1,12']]);
        $p = $this->periods->close($place, $request->user(), (int) $data['year'], (int) $data['month']);

        return $this->ok(['year' => $p->year, 'month' => $p->month, 'status' => $p->status], 'Closed - nothing more can be posted into it.');
    }

    /** POST /accounting/periods/reopen {year, month, reason} - for a place below, ?territory_id= */
    public function reopen(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if (! AccountingAccess::canReopen($request->user(), $place)) {
            return $this->forbidden('A closed month is reopened by the level above, with a reason.');
        }
        $data = $request->validate(['year' => ['required', 'integer'], 'month' => ['required', 'integer', 'between:1,12'], 'reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say why it is being reopened.']);
        $p = $this->periods->reopen($place, $request->user(), (int) $data['year'], (int) $data['month'], $data['reason']);

        return $this->ok(['year' => $p->year, 'month' => $p->month, 'status' => $p->status], 'Reopened.');
    }
}
