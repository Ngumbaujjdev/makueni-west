<?php

namespace App\Http\Controllers\Api\HR;

use App\Services\HR\Lists;
use App\Support\HrAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Positions & pay (docs/specs/hr-spec.md): the positions, grades and
 * allowance types this place uses - its own, and those set by the places
 * above, which it can switch off here.
 */
class SetupController extends HrBase
{
    public const KINDS = ['position', 'grade', 'allowance'];

    public function __construct(private Lists $lists) {}

    /** GET /hr/setup */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $can = HrAccess::abilities($request->user(), $place);
        $out = ['place' => $this->placeInfo($place), 'can' => $can];
        foreach (['position' => 'positions', 'grade' => 'grades', 'allowance' => 'allowances'] as $kind => $key) {
            $hidden = $this->lists->hidden($place, $kind);
            $out[$key] = $this->lists->rows($place, $kind)->map(fn ($r) => $this->lists->present($place, $kind, $r, $hidden, $can['setup']))->values();
        }

        return $this->ok($out);
    }

    /** POST /hr/setup/{kind} · PUT /hr/setup/{kind}/{id} */
    public function save(Request $request, string $kind, ?int $id = null): JsonResponse
    {
        $place = $this->place($request, 'setup');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $row = null;
        if ($id) {
            $row = (Lists::KINDS[$kind])::whereIn('territory_id', $this->lists->owners($place))->find($id);
            if (! $row) {
                return $this->notFound("That {$kind} isn't used here.");
            }
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'code' => [$kind === 'grade' ? 'required' : 'nullable', 'string', 'max:20'],
            'min_pay' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'max_pay' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'default_pay' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'default_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'levels' => ['nullable', 'array'],
            'levels.*' => ['in:church,region,diocese'],
            'grade_id' => ['nullable', 'integer'],
        ], ['name.required' => 'Give it a name.', 'code.required' => 'Give the grade a short code, e.g. G3.']);
        $row = $this->lists->save($place, $request->user(), $kind, $data, $row);

        return $this->ok($this->lists->present($place, $kind, $row, $this->lists->hidden($place, $kind), true), $id ? 'Saved.' : 'Added.', $id ? 200 : 201);
    }

    /** DELETE /hr/setup/{kind}/{id} - only an unused row of our own. */
    public function remove(Request $request, string $kind, int $id): JsonResponse
    {
        $place = $this->place($request, 'setup');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $row = (Lists::KINDS[$kind])::whereIn('territory_id', $this->lists->owners($place))->find($id);
        if (! $row) {
            return $this->notFound("That {$kind} isn't used here.");
        }
        $this->lists->remove($place, $kind, $row);

        return $this->ok(null, 'Removed.');
    }

    /** POST /hr/setup/{kind}/{id}/here {on} - use, or don't use, one set by a place above. */
    public function here(Request $request, string $kind, int $id): JsonResponse
    {
        $place = $this->place($request, 'setup');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $row = (Lists::KINDS[$kind])::whereIn('territory_id', $this->lists->owners($place))->find($id);
        if (! $row) {
            return $this->notFound("That {$kind} isn't used here.");
        }
        $on = $request->validate(['on' => ['required', 'boolean']])['on'];
        $this->lists->here($place, $kind, $row, (bool) $on);

        return $this->ok($this->lists->present($place, $kind, $row, $this->lists->hidden($place, $kind), true), $on ? 'Used here again.' : 'Not used here any more.');
    }
}
