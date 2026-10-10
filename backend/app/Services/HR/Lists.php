<?php

namespace App\Services\HR;

use App\Models\Employee;
use App\Models\HrAllowanceType;
use App\Models\HrGrade;
use App\Models\HrPosition;
use App\Models\Territory;
use App\Models\User;
use App\Support\PlaceAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The lists each level sets up (docs/specs/hr-spec.md): positions, grades and
 * allowance types. A place sees its own and those of the places above it -
 * the diocese's are everyone's defaults, a region's are its churches' - and
 * can switch off one it doesn't use. Only the place that owns a row changes it.
 */
final class Lists
{
    public const KINDS = ['position' => HrPosition::class, 'grade' => HrGrade::class, 'allowance' => HrAllowanceType::class];

    public const LABELS = ['position' => 'position', 'grade' => 'grade', 'allowance' => 'allowance'];

    /** The place and those above it, the place first. @return int[] */
    public function owners(Territory $place): array
    {
        return [(int) $place->id, ...array_map(fn ($t) => (int) $t->id, PlaceAccess::ancestors($place))];
    }

    /** Every row this place sees - its own and the places above's - the place's own last. */
    public function rows(Territory $place, string $kind): Collection
    {
        $owners = $this->owners($place);
        $class = self::KINDS[$kind];

        return $class::whereIn('territory_id', $owners)->get()
            ->sortBy(fn ($r) => [count($owners) - array_search((int) $r->territory_id, $owners, true), $r->display_order, mb_strtolower($r->name ?? '')])->values();
    }

    /** Ids of the defaults switched off here. @return int[] */
    public function hidden(Territory $place, string $kind): array
    {
        return DB::table('hr_hidden')->where('territory_id', $place->id)->where('kind', $kind)->pluck('item_id')->map(fn ($id) => (int) $id)->all();
    }

    /** What can be picked here: on, not switched off here, and (positions) meant for this level. */
    public function usable(Territory $place, string $kind): Collection
    {
        $hidden = $this->hidden($place, $kind);
        $level = $place->territory_type->value;

        return $this->rows($place, $kind)->filter(fn ($r) => $r->is_active && ! in_array((int) $r->id, $hidden, true)
            && ($kind !== 'position' || $r->fitsLevel($level)))->values();
    }

    public function findUsable(Territory $place, string $kind, ?int $id): ?Model
    {
        return $id ? $this->usable($place, $kind)->firstWhere('id', $id) : null;
    }

    /** Add or change one of this place's own rows. */
    public function save(Territory $place, User $user, string $kind, array $d, ?Model $row = null): Model
    {
        if ($row && (int) $row->territory_id !== (int) $place->id) {
            throw ValidationException::withMessages(['id' => ['Only the place that set it up changes it.']]);
        }
        $class = self::KINDS[$kind];
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Give it a name.']]);
        }
        $fields = ['name' => mb_substr($name, 0, $kind === 'allowance' ? 60 : 100), 'description' => $this->text($d['description'] ?? null)];
        if (array_key_exists('is_active', $d)) {
            $fields['is_active'] = (bool) $d['is_active'];
        }
        if ($kind === 'grade') {
            $code = strtoupper(trim((string) ($d['code'] ?? '')));
            if ($code === '') {
                throw ValidationException::withMessages(['code' => ['Give the grade a short code, e.g. G3.']]);
            }
            $min = $this->money($d['min_pay'] ?? null);
            $max = $this->money($d['max_pay'] ?? null);
            $usual = $this->money($d['default_pay'] ?? null);
            if ($min !== null && $max !== null && $max < $min) {
                throw ValidationException::withMessages(['max_pay' => ['The most can\'t be less than the least.']]);
            }
            if ($usual !== null && (($min !== null && $usual < $min) || ($max !== null && $usual > $max))) {
                throw ValidationException::withMessages(['default_pay' => ['The usual pay must be inside the range.']]);
            }
            $fields += ['code' => mb_substr($code, 0, 20), 'min_pay' => $min, 'max_pay' => $max, 'default_pay' => $usual];
            $clash = $class::where('territory_id', $place->id)->where('code', $fields['code'])->when($row, fn ($q) => $q->where('id', '!=', $row->id))->exists();
            if ($clash) {
                throw ValidationException::withMessages(['code' => ["There is already a grade {$fields['code']} here."]]);
            }
        } else {
            $clash = $class::where('territory_id', $place->id)->where('name', $fields['name'])->when($row, fn ($q) => $q->where('id', '!=', $row->id))->exists();
            if ($clash) {
                throw ValidationException::withMessages(['name' => ["There is already a {$kind} called {$fields['name']} here."]]);
            }
        }
        if ($kind === 'position') {
            $levels = array_values(array_intersect(PlaceAccess::LEVELS, (array) ($d['levels'] ?? [])));
            $fields['levels'] = $levels ?: null;
            $grade = isset($d['grade_id']) && $d['grade_id'] ? $this->findUsable($place, 'grade', (int) $d['grade_id']) : null;
            if (! empty($d['grade_id']) && ! $grade) {
                throw ValidationException::withMessages(['grade_id' => ['Pick a grade used here.']]);
            }
            $fields['grade_id'] = $grade?->id;
        }
        if ($kind === 'allowance') {
            $fields['default_amount'] = $this->money($d['default_amount'] ?? null);
        }
        if ($row) {
            $row->update($fields);

            return $row->fresh();
        }

        return $class::create($fields + ['territory_id' => $place->id, 'created_by' => $user->id,
            'display_order' => (int) $class::where('territory_id', $place->id)->max('display_order') + 1]);
    }

    /** Remove one of this place's own rows - only when nobody uses it (else it is switched off). */
    public function remove(Territory $place, string $kind, Model $row): void
    {
        if ((int) $row->territory_id !== (int) $place->id) {
            throw ValidationException::withMessages(['id' => ['Only the place that set it up removes it.']]);
        }
        if ($n = $this->inUse($kind, $row)) {
            throw ValidationException::withMessages(['id' => ["It is in use ({$n}) - switch it off instead."]]);
        }
        DB::table('hr_hidden')->where('kind', $kind)->where('item_id', $row->id)->delete();
        $row->delete();
    }

    /** Use or don't use a row from a place above, here. */
    public function here(Territory $place, string $kind, Model $row, bool $on): void
    {
        if (! in_array((int) $row->territory_id, $this->owners($place), true) || (int) $row->territory_id === (int) $place->id) {
            throw ValidationException::withMessages(['id' => ['Switch your own off by changing it.']]);
        }
        $on ? DB::table('hr_hidden')->where(['territory_id' => $place->id, 'kind' => $kind, 'item_id' => $row->id])->delete()
            : DB::table('hr_hidden')->insertOrIgnore(['territory_id' => $place->id, 'kind' => $kind, 'item_id' => $row->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** How many staff (and positions, for a grade) use it. */
    public function inUse(string $kind, Model $row): int
    {
        return match ($kind) {
            'position' => Employee::where('position_id', $row->id)->count(),
            'grade' => Employee::where('grade_id', $row->id)->count() + HrPosition::where('grade_id', $row->id)->count(),
            'allowance' => Employee::whereJsonContains('allowances', ['type_id' => (int) $row->id])->count(),
        };
    }

    public function present(Territory $place, string $kind, Model $row, array $hidden, bool $canSetup): array
    {
        $own = (int) $row->territory_id === (int) $place->id;
        $owner = $own ? $place : Territory::find($row->territory_id);
        $out = [
            'id' => $row->id, 'name' => $row->name, 'description' => $row->description, 'is_active' => (bool) $row->is_active,
            'owner' => ['id' => $owner?->id, 'name' => $owner?->name, 'level' => $owner?->territory_type?->value],
            'own' => $own, 'hidden' => in_array((int) $row->id, $hidden, true), 'in_use' => $this->inUse($kind, $row),
            'can' => ['edit' => $canSetup && $own, 'toggle_here' => $canSetup && ! $own],
        ];

        return $out + match ($kind) {
            'position' => ['levels' => $row->levels ?? [], 'grade_id' => $row->grade_id, 'grade' => $row->grade ? $row->grade->code.' · '.$row->grade->name : null],
            'grade' => ['code' => $row->code, 'min_pay' => $this->num($row->min_pay), 'max_pay' => $this->num($row->max_pay), 'default_pay' => $this->num($row->default_pay)],
            'allowance' => ['default_amount' => $this->num($row->default_amount)],
        };
    }

    private function money($v): ?float
    {
        return $v === null || $v === '' ? null : round(max((float) $v, 0), 2);
    }

    private function num($v): ?float
    {
        return $v === null ? null : (float) $v;
    }

    private function text(?string $s): ?string
    {
        $s = trim((string) $s);

        return $s === '' ? null : mb_substr($s, 0, 255);
    }
}
