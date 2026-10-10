<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Services\Accounting\Trail;
use App\Support\AccountingAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A record's trail for its page (docs/specs/accounting-spec.md, Redesign R1):
 * what happened to it and the documents it is chained to. Seen by whoever may
 * read the books where it sits.
 */
class TrailController extends AccountingBase
{
    public function __construct(private Trail $trail) {}

    public function show(Request $request, string $type, int $id): JsonResponse
    {
        $record = $this->trail->find($type, $id);
        if (! $record || ! collect($this->trail->places($record))->contains(fn ($p) => AccountingAccess::canRead($request->user(), $p))) {
            return $this->notFound('That record isn\'t in the books.');
        }
        // A person on the payroll - their pay and where it goes - only for whoever sees the payroll there.
        if ($record instanceof \App\Models\Employee) {
            $place = \App\Models\Territory::find($record->territory_id);
            if (! $place || ! (AccountingAccess::can($request->user(), $place, 'payroll') || AccountingAccess::can($request->user(), $place, 'payrollread'))) {
                return $this->notFound('That record isn\'t in the books.');
            }
        }

        return $this->ok($this->trail->for($record));
    }
}
