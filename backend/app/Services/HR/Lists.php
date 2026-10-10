<?php

namespace App\Services\HR;

use App\Models\Employee;
use App\Models\HrAllowanceType;
use App\Models\HrGrade;
use App\Models\HrPlaceSetting;
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
        HrPlaceSetting::where('kind', $kind)->where('item_id', $row->id)->delete();
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
        $eff = $this->effective($place, $kind, $row);
        $v = $eff['values'];
        $out = [
            'id' => $row->id, 'name' => $row->name, 'description' => $row->description, 'is_active' => (bool) $row->is_active,
            'owner' => ['id' => $owner?->id, 'name' => $owner?->name, 'level' => $owner?->territory_type?->value],
            'own' => $own, 'hidden' => in_array((int) $row->id, $hidden, true), 'in_use' => $this->inUse($kind, $row),
            // Our own version of one set above us, and whose version applies here.
            'ours' => ! $own && $eff['from'] && (int) $eff['from']['id'] === (int) $place->id, 'version_from' => $eff['from'],
            'can' => ['edit' => $canSetup && $own, 'toggle_here' => $canSetup && ! $own, 'ours' => $canSetup && ! $own],
        ];
        $grade = $kind === 'position' && ! empty($v['grade_id']) ? HrGrade::find($v['grade_id']) : null;

        return $out + match ($kind) {
            'position' => ['levels' => $row->levels ?? [], 'grade_id' => $v['grade_id'], 'grade' => $grade ? $grade->code.' · '.$grade->name : null,
                'default_pay' => $v['default_pay'], 'allowances' => $this->namedAllowances($place, $v['allowances']), 'duties' => $v['duties'], 'notes' => $v['notes']],
            'grade' => ['code' => $row->code, 'min_pay' => $v['min_pay'], 'max_pay' => $v['max_pay'], 'default_pay' => $v['default_pay']],
            'allowance' => ['default_amount' => $v['default_amount']],
        };
    }

    // ------------------------------------------------------------ our version of one set above us

    /** What the row itself says - before any place's own version. */
    public function base(string $kind, Model $row): array
    {
        return match ($kind) {
            'position' => ['grade_id' => $row->grade_id, 'default_pay' => null, 'allowances' => [], 'duties' => $row->description, 'notes' => null],
            'grade' => ['min_pay' => $this->num($row->min_pay), 'max_pay' => $this->num($row->max_pay), 'default_pay' => $this->num($row->default_pay)],
            'allowance' => ['default_amount' => $this->num($row->default_amount)],
        };
    }

    /**
     * What applies here: the row, with the nearest version laid over it - this
     * place's own, else the region's, ... up to (not including) the owner.
     *
     * @return array{values: array, from: ?array}
     */
    public function effective(Territory $place, string $kind, Model $row, bool $skipPlace = false): array
    {
        $chain = [];
        foreach ($this->owners($place) as $id) {
            if ($id === (int) $row->territory_id) {
                break;
            }
            $chain[] = $id;
        }
        if ($skipPlace) {
            array_shift($chain);
        }
        $values = $this->base($kind, $row);
        if ($chain) {
            $versions = HrPlaceSetting::where('kind', $kind)->where('item_id', $row->id)->whereIn('territory_id', $chain)->get()->keyBy('territory_id');
            foreach ($chain as $id) {
                if ($s = $versions->get($id)) {
                    $t = Territory::find($id);

                    return ['values' => array_merge($values, array_intersect_key($s->settings ?? [], $values)), 'from' => ['id' => $id, 'name' => $t?->name, 'level' => $t?->territory_type?->value]];
                }
            }
        }
        $owner = Territory::find($row->territory_id);

        return ['values' => $values, 'from' => $owner ? ['id' => $owner->id, 'name' => $owner->name, 'level' => $owner->territory_type?->value] : null];
    }

    /** This place's own version, or null. */
    public function ours(Territory $place, string $kind, Model $row): ?array
    {
        return HrPlaceSetting::where(['territory_id' => $place->id, 'kind' => $kind, 'item_id' => $row->id])->first()?->settings;
    }

    /** Set this place's own version of a row set above it. */
    public function setOurs(Territory $place, User $user, string $kind, Model $row, array $d): array
    {
        if ((int) $row->territory_id === (int) $place->id || ! in_array((int) $row->territory_id, $this->owners($place), true)) {
            throw ValidationException::withMessages(['id' => ['Change your own by changing it - this is for one set above you.']]);
        }
        $v = match ($kind) {
            'position' => $this->positionPackage($place, $d),
            'grade' => $this->gradeRange($d),
            'allowance' => ['default_amount' => $this->money($d['default_amount'] ?? null)],
        };
        HrPlaceSetting::updateOrCreate(['territory_id' => $place->id, 'kind' => $kind, 'item_id' => $row->id], ['settings' => $v, 'updated_by' => $user->id, 'created_by' => $user->id]);

        return $v;
    }

    /** Back to the version from above. */
    public function clearOurs(Territory $place, string $kind, Model $row): void
    {
        HrPlaceSetting::where(['territory_id' => $place->id, 'kind' => $kind, 'item_id' => $row->id])->delete();
    }

    /** A position's package here: grade, usual basic pay (inside the grade's range here), allowances, duties. */
    private function positionPackage(Territory $place, array $d): array
    {
        $grade = ! empty($d['grade_id']) ? $this->findUsable($place, 'grade', (int) $d['grade_id']) : null;
        if (! empty($d['grade_id']) && ! $grade) {
            throw ValidationException::withMessages(['grade_id' => ['Pick a grade used here.']]);
        }
        $pay = $this->money($d['default_pay'] ?? null);
        if ($grade && $pay !== null) {
            $r = $this->effective($place, 'grade', $grade)['values'];
            if (($r['min_pay'] !== null && $pay < $r['min_pay']) || ($r['max_pay'] !== null && $pay > $r['max_pay'])) {
                throw ValidationException::withMessages(['default_pay' => ["Grade {$grade->code} pays ".number_format((float) $r['min_pay']).' to '.number_format((float) $r['max_pay']).' here.']]);
            }
        }
        $allowances = [];
        foreach (array_values((array) ($d['allowances'] ?? [])) as $i => $a) {
            $type = ! empty($a['type_id']) ? $this->findUsable($place, 'allowance', (int) $a['type_id']) : null;
            if (! $type) {
                throw ValidationException::withMessages(["allowances.{$i}.type_id" => ['Pick an allowance used here.']]);
            }
            // No amount: it follows the allowance's usual amount wherever the package is used.
            $amount = $this->money($a['amount'] ?? null);
            if (! $amount && ! $this->effective($place, 'allowance', $type)['values']['default_amount']) {
                throw ValidationException::withMessages(["allowances.{$i}.amount" => ["How much is {$type->name}?"]]);
            }
            $allowances[] = ['type_id' => $type->id, 'amount' => $amount ?: null];
        }
        $text = fn ($v, $max) => ($t = trim((string) $v)) === '' ? null : mb_substr($t, 0, $max);

        return ['grade_id' => $grade?->id, 'default_pay' => $pay, 'allowances' => $allowances, 'duties' => $text($d['duties'] ?? null, 1000), 'notes' => $text($d['notes'] ?? null, 500)];
    }

    private function gradeRange(array $d): array
    {
        $min = $this->money($d['min_pay'] ?? null);
        $max = $this->money($d['max_pay'] ?? null);
        $usual = $this->money($d['default_pay'] ?? null);
        if ($min !== null && $max !== null && $max < $min) {
            throw ValidationException::withMessages(['max_pay' => ['The most can\'t be less than the least.']]);
        }
        if ($usual !== null && (($min !== null && $usual < $min) || ($max !== null && $usual > $max))) {
            throw ValidationException::withMessages(['default_pay' => ['The usual pay must be inside the range.']]);
        }

        return ['min_pay' => $min, 'max_pay' => $max, 'default_pay' => $usual];
    }

    /** [{type_id, amount}] with each allowance's name. */
    public function namedAllowances(Territory $place, array $list): array
    {
        $types = HrAllowanceType::whereIn('id', array_column($list, 'type_id'))->get()->keyBy('id');

        return array_values(array_map(fn ($a) => [
            'type_id' => (int) $a['type_id'], 'name' => $types[$a['type_id']]->name ?? 'Allowance',
            'amount' => (float) ($a['amount'] ?? (isset($types[$a['type_id']]) ? $this->effective($place, 'allowance', $types[$a['type_id']])['values']['default_amount'] : 0)),
            'follows' => ($a['amount'] ?? null) === null,
        ], $list));
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
