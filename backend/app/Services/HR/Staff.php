<?php

namespace App\Services\HR;

use App\Models\Employee;
use App\Models\Payslip;
use App\Models\Person;
use App\Models\StaffPosting;
use App\Models\Territory;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use App\Support\PayTo;
use App\Support\PlaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The people a place employs (docs/specs/hr-spec.md) - the one record payroll
 * pays from: who they are (a member, a login, or a name), their job and
 * grade, what they are paid and how, where they have been posted. Moving
 * someone to another place is a transfer, made from the level above; the
 * place they were last posted to in a month pays that month.
 */
final class Staff
{
    public function __construct(private Lists $lists) {}

    public function save(Territory $place, User $user, array $d, ?Employee $e = null): Employee
    {
        $person = null;
        if (! empty($d['person_id'])) {
            $person = Person::whereNull('anonymised_at')->find((int) $d['person_id']);
            if (! $person || ! in_array((int) $person->territory_id, $this->churchIds($place), true)) {
                throw ValidationException::withMessages(['person_id' => ['Pick a member of this church, or of a church below.']]);
            }
        }
        $login = null;
        if (! empty($d['user_id'])) {
            $login = User::find((int) $d['user_id']);
            if (! $login || ! $this->loginsQuery($place)->where('users.id', $login->id)->exists()) {
                throw ValidationException::withMessages(['user_id' => ['Pick someone with a role here or below.']]);
            }
        }
        $name = trim((string) ($d['name'] ?? '')) ?: ($person?->name ?? $login?->full_name ?? '');
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Who is it?']]);
        }
        $position = ! empty($d['position_id']) ? $this->lists->findUsable($place, 'position', (int) $d['position_id']) : null;
        if (! empty($d['position_id']) && ! $position && (int) $d['position_id'] !== (int) $e?->position_id) {
            throw ValidationException::withMessages(['position_id' => ['Pick a position used here.']]);
        }
        $position ??= ! empty($d['position_id']) ? $e?->positionRow : null;
        // The position's package here (this place's version, else the nearest above): grade, usual pay, allowances.
        $package = $position ? $this->lists->effective($place, 'position', $position)['values'] : null;
        $gradeId = array_key_exists('grade_id', $d) ? ($d['grade_id'] ?: null) : ($e ? $e->grade_id : ($package['grade_id'] ?? null));
        $grade = $gradeId ? ($this->lists->findUsable($place, 'grade', (int) $gradeId) ?? ((int) $gradeId === (int) $e?->grade_id ? $e->grade : null)) : null;
        if ($gradeId && ! $grade) {
            throw ValidationException::withMessages(['grade_id' => ['Pick a grade used here.']]);
        }
        $range = $grade ? (object) $this->lists->effective($place, 'grade', $grade)['values'] : null;
        $basic = array_key_exists('basic_pay', $d) && $d['basic_pay'] !== null && $d['basic_pay'] !== '' ? round(max((float) $d['basic_pay'], 0), 2)
            : ($e ? (float) $e->basic_pay : (float) ($package['default_pay'] ?? $range?->default_pay ?? 0));
        if ($range && (($range->min_pay !== null && $basic < (float) $range->min_pay) || ($range->max_pay !== null && $basic > (float) $range->max_pay))) {
            throw ValidationException::withMessages(['basic_pay' => ["Grade {$grade->code} pays ".$this->range($range).' a month.']]);
        }
        $given = $d['allowances'] ?? null;
        $allowances = $this->allowances($place, $given ?? ($e ? ($e->allowances ?? []) : ($package['allowances'] ?? [])));
        if (! empty($d['start_date']) && ! empty($d['end_date']) && $d['end_date'] < $d['start_date']) {
            throw ValidationException::withMessages(['end_date' => ['They can\'t leave before they started.']]);
        }
        if (! empty($d['start_date']) && ! empty($d['contract_end']) && $d['contract_end'] < $d['start_date']) {
            throw ValidationException::withMessages(['contract_end' => ['The contract can\'t end before they started.']]);
        }
        $fields = [
            'person_id' => $person?->id ?? (array_key_exists('person_id', $d) ? null : $e?->person_id),
            'user_id' => $login?->id ?? (array_key_exists('user_id', $d) ? null : $e?->user_id),
            'name' => mb_substr($name, 0, 150),
            'phone' => $this->clean($d['phone'] ?? null, 30) ?? ($e ? null : ($person?->phone ?? $login?->phone)),
            'email' => $this->clean($d['email'] ?? null, 150) ?? ($e ? null : $login?->email),
            'position' => $position ? $position->name : $this->clean($d['position'] ?? null, 100),
            'position_id' => $position?->id,
            'grade_id' => $grade?->id,
            'employment_type' => isset(Employee::TYPES[$d['employment_type'] ?? '']) ? $d['employment_type'] : ($e?->employment_type ?? 'full_time'),
            'start_date' => $d['start_date'] ?? null,
            'end_date' => $d['end_date'] ?? null,
            'contract_end' => $d['contract_end'] ?? null,
            'pay_method' => in_array($d['pay_method'] ?? '', array_keys(Employee::METHODS), true) ? $d['pay_method'] : ($e?->pay_method ?? 'mpesa'),
            'pay_to' => $this->clean($d['pay_to'] ?? null, 150),
            // Where to pay them, in a shape the treasurer can pay from; it decides the method and the one-line "pay to".
            'payee' => array_key_exists('payee', $d) ? PayTo::from($d['payee']) : $e?->payee,
            'basic_pay' => $basic,
            'allowances' => $allowances,
            'is_active' => array_key_exists('is_active', $d) ? (bool) $d['is_active'] : ($e?->is_active ?? true),
        ];
        // Personal numbers: a value replaces the saved one; left blank, the saved one stays.
        foreach (Employee::PRIVATE as $key) {
            if (($v = $this->clean($d[$key] ?? null, 40)) !== null) {
                $fields[$key] = strtoupper($v);
            }
        }
        if ($fields['payee']) {
            $fields['pay_method'] = $fields['payee']['method'];
            $fields['pay_to'] = mb_substr((string) PayTo::describe($fields['payee']), 0, 150);
        }
        if ($basic + array_sum(array_column($allowances, 'amount')) <= 0) {
            throw ValidationException::withMessages(['basic_pay' => ['Enter what they are paid a month.']]);
        }

        return DB::transaction(function () use ($place, $user, $e, $fields) {
            if (! $e) {
                $e = Employee::create($fields + ['territory_id' => $place->id, 'created_by' => $user->id]);
                $this->post($e, $place, (string) ($fields['start_date'] ?: now()->toDateString()), 'hired', $user, null, $fields['end_date']);

                return $e->fresh();
            }
            $jobChanged = ($e->position_id && (int) $e->position_id !== (int) $fields['position_id']) || ($e->position !== $fields['position']);
            $e->update($fields);
            $open = $this->current($e);
            if ($open) {
                if ($jobChanged && $open->from_date->lt(CarbonImmutable::today())) {
                    $open->update(['to_date' => CarbonImmutable::yesterday()->toDateString()]);
                    $this->post($e, Territory::findOrFail($e->territory_id), now()->toDateString(), 'changed', $user, null, $fields['end_date']);
                } else {
                    $open->update(['position' => $fields['position'], 'position_id' => $fields['position_id'], 'to_date' => $fields['end_date']]
                        + ($open->reason === 'hired' && $fields['start_date'] ? ['from_date' => $fields['start_date']] : []));
                }
            }

            return $e->fresh();
        });
    }

    /** Move someone to another place from a date: their old posting ends the day before. */
    public function transfer(Employee $e, User $user, Territory $to, string $date, ?int $positionId = null, ?string $note = null): Employee
    {
        if ((int) $to->id === (int) $e->territory_id) {
            throw ValidationException::withMessages(['to_territory_id' => ['They already work there.']]);
        }
        $day = CarbonImmutable::parse($date)->startOfDay();
        $open = $this->current($e);
        if ($open && ! $day->gt($open->from_date)) {
            throw ValidationException::withMessages(['date' => ['They only started there on '.$open->from_date->format('j M Y').' - pick a later day.']]);
        }
        if ($e->end_date && $day->gt($e->end_date)) {
            throw ValidationException::withMessages(['date' => ['They left on '.$e->end_date->format('j M Y').'.']]);
        }
        $from = Territory::findOrFail($e->territory_id);
        $taken = Payslip::where('employee_id', $e->id)->whereHas('run', fn ($r) => $r->where('territory_id', $from->id)->where('status', '!=', 'cancelled')->where('month', '>=', $day->format('Y-m')))
            ->with('run')->first();
        if ($taken) {
            throw ValidationException::withMessages(['date' => ["{$from->name}'s payroll for ".date('F Y', strtotime($taken->run->month.'-01')).' already includes them - take them off it, or transfer them from the month after.']]);
        }
        $position = $positionId ? $this->lists->findUsable($to, 'position', $positionId) : null;
        if ($positionId && ! $position) {
            throw ValidationException::withMessages(['position_id' => ["Pick a position used at {$to->name}."]]);
        }
        $keep = ! $position && $e->position_id && $this->lists->findUsable($to, 'position', (int) $e->position_id);

        return DB::transaction(function () use ($e, $user, $to, $day, $open, $position, $keep, $note) {
            $open?->update(['to_date' => $day->subDay()->toDateString()]);
            $e->update([
                'territory_id' => $to->id,
                'position' => $position?->name ?? $e->position,
                'position_id' => $position?->id ?? ($keep ? $e->position_id : null),
                // A grade from the old place's own list may not be used at the new one.
                'grade_id' => $e->grade_id && $this->lists->findUsable($to, 'grade', (int) $e->grade_id) ? $e->grade_id : null,
            ]);
            $this->post($e->fresh(), $to, $day->toDateString(), 'transferred', $user, $note, $e->end_date?->toDateString());

            return $e->fresh();
        });
    }

    /** They leave on a date: paid for that month, not after. */
    public function end(Employee $e, User $user, string $date, ?string $reason = null): Employee
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        if ($e->start_date && $day->lt($e->start_date)) {
            throw ValidationException::withMessages(['date' => ['They can\'t leave before they started.']]);
        }

        return DB::transaction(function () use ($e, $user, $day, $reason) {
            $e->update(['end_date' => $day->toDateString()]);
            $this->current($e)?->update(['to_date' => $day->toDateString()]);
            StaffPosting::create(['employee_id' => $e->id, 'territory_id' => $e->territory_id, 'position' => $e->position, 'position_id' => $e->position_id,
                'from_date' => $day->toDateString(), 'to_date' => $day->toDateString(), 'reason' => 'left', 'note' => $this->clean($reason, 255), 'created_by' => $user->id]);

            return $e->fresh();
        });
    }

    /** Only someone never on a payslip can be removed; anyone else is ended. */
    public function delete(Employee $e): void
    {
        if (Payslip::where('employee_id', $e->id)->exists()) {
            throw ValidationException::withMessages(['employee' => ["{$e->name} has been paid - end their time instead, so their payslips keep their name."]]);
        }
        DB::transaction(function () use ($e) {
            $e->clearMediaCollection('documents');
            $e->postings()->delete();
            $e->delete();
        });
    }

    // ------------------------------------------------------------ who can be picked

    /** The churches whose members can be picked here: the church itself, or those below. @return int[] */
    public function churchIds(Territory $place): array
    {
        if ($place->territory_type->value === 'church') {
            return [(int) $place->id];
        }

        return Territory::whereIn('id', PlaceAccess::descendantIds($place))->where('territory_type', 'church')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Users with a role here or below. */
    public function loginsQuery(Territory $place)
    {
        $ids = [(int) $place->id, ...PlaceAccess::descendantIds($place)];

        return User::query()->whereIn('users.id', UserTerritoryAssignment::whereIn('territory_id', $ids)->effective()->select('user_id'));
    }

    // ------------------------------------------------------------ showing

    public function present(Employee $e, bool $full = false): array
    {
        $place = Territory::find($e->territory_id);
        $today = CarbonImmutable::today();
        $status = $e->end_date && $e->end_date->lt($today) ? 'left' : ($e->start_date && $e->start_date->gt($today) ? 'starting' : ($e->is_active ? 'active' : 'off'));
        $allowances = array_map(fn ($a) => ['type_id' => $a['type_id'] ?? null, 'name' => $a['name'], 'amount' => (float) $a['amount']], $e->allowances ?? []);
        $out = [
            'id' => $e->id, 'name' => $e->name, 'phone' => $e->phone, 'email' => $e->email,
            'person' => $e->person_id ? ['id' => $e->person_id, 'name' => $e->person?->name, 'church' => Territory::find($e->person?->territory_id)?->name] : null,
            'user' => $e->user_id ? ['id' => $e->user_id, 'name' => $e->user?->full_name] : null,
            'position' => $e->position, 'position_id' => $e->position_id,
            'grade' => $e->grade_id ? ['id' => $e->grade_id, 'code' => $e->grade?->code, 'name' => $e->grade?->name] : null,
            'employment_type' => $e->employment_type, 'employment_type_label' => Employee::TYPES[$e->employment_type] ?? $e->employment_type,
            'start_date' => $e->start_date?->toDateString(), 'end_date' => $e->end_date?->toDateString(), 'contract_end' => $e->contract_end?->toDateString(),
            'status' => $status, 'is_active' => (bool) $e->is_active,
            'place' => ['id' => $place?->id, 'name' => $place?->name, 'level' => $place?->territory_type?->value],
            'pay_method' => $e->pay_method, 'pay_method_label' => Employee::METHODS[$e->pay_method] ?? $e->pay_method, 'pay_to' => $e->pay_to, 'payee' => $e->payee,
            'basic_pay' => (float) $e->basic_pay, 'allowances' => $allowances, 'gross' => round((float) $e->basic_pay + array_sum(array_column($allowances, 'amount')), 2),
            // Masked, always - the full numbers never leave the server.
            'id_number' => Employee::mask($e->id_number), 'kra_pin' => Employee::mask($e->kra_pin),
        ];
        if (! $full) {
            return $out;
        }
        $slips = Payslip::where('employee_id', $e->id)->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')->whereIn('payroll_runs.status', ['posted', 'paid']);
        $months = (clone $slips)->orderByDesc('payroll_runs.month')->limit(60)
            ->get(['payslips.id', 'payroll_runs.id as run_id', 'payroll_runs.month', 'payroll_runs.status', 'payroll_runs.territory_id', 'payslips.gross', 'payslips.total_deductions', 'payslips.net', 'payslips.position']);
        $places = Territory::whereIn('id', $months->pluck('territory_id')->unique())->pluck('name', 'id');
        $year = now()->format('Y');

        return $out + [
            'postings' => $e->postings()->with('territory')->orderByDesc('from_date')->orderByDesc('id')->get()->map(fn (StaffPosting $p) => [
                'id' => $p->id, 'place' => $p->territory?->name, 'place_id' => $p->territory_id, 'position' => $p->position, 'from' => $p->from_date?->toDateString(), 'to' => $p->to_date?->toDateString(),
                'reason' => $p->reason, 'reason_label' => StaffPosting::REASONS[$p->reason] ?? $p->reason, 'note' => $p->note,
            ])->values(),
            'documents' => $e->getMedia('documents')->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'file_name' => $m->file_name, 'mime' => $m->mime_type, 'size' => $m->size, 'added_at' => $m->created_at?->toIso8601String()])->values(),
            'paid' => ['slips' => (clone $slips)->count(), 'last_month' => (clone $slips)->max('payroll_runs.month')],
            'payslips' => $months->map(fn ($m) => [
                'id' => $m->id, 'run_id' => $m->run_id, 'month' => $m->month, 'label' => date('F Y', strtotime("{$m->month}-01")), 'status' => $m->status,
                'place' => $places[$m->territory_id] ?? null, 'position' => $m->position,
                'gross' => (float) $m->gross, 'deductions' => (float) $m->total_deductions, 'net' => (float) $m->net,
            ])->values(),
            'totals' => [
                'this_year' => round((float) $months->filter(fn ($m) => str_starts_with($m->month, $year))->sum('net'), 2),
                'all' => round((float) (clone $slips)->sum('payslips.net'), 2),
                'months' => (clone $slips)->count(),
            ],
            'can_delete' => ! Payslip::where('employee_id', $e->id)->exists(),
        ];
    }

    /** The people at these places, filtered: q, position_id, type, status (active | left | all). */
    public function query(array $placeIds, array $f)
    {
        $q = Employee::query()->whereIn('territory_id', $placeIds);
        $today = now()->toDateString();
        match ($f['status'] ?? 'active') {
            'left' => $q->whereNotNull('end_date')->where('end_date', '<', $today),
            'all' => null,
            default => $q->where(fn ($w) => $w->whereNull('end_date')->orWhere('end_date', '>=', $today)),
        };
        if (! empty($f['position_id'])) {
            $q->where('position_id', (int) $f['position_id']);
        }
        if (! empty($f['type']) && isset(Employee::TYPES[$f['type']])) {
            $q->where('employment_type', $f['type']);
        }
        if (($term = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('position', 'like', $like)->orWhere('phone', 'like', $like));
        }

        return $q;
    }

    // ------------------------------------------------------------ helpers

    private function current(Employee $e): ?StaffPosting
    {
        return $e->postings()->where('reason', '!=', 'left')->orderByDesc('from_date')->orderByDesc('id')->first();
    }

    private function post(Employee $e, Territory $place, string $from, string $reason, User $user, ?string $note = null, $to = null): StaffPosting
    {
        return StaffPosting::create(['employee_id' => $e->id, 'territory_id' => $place->id, 'position' => $e->position, 'position_id' => $e->position_id,
            'from_date' => $from, 'to_date' => $to ?: null, 'reason' => $reason, 'note' => $this->clean($note, 255), 'created_by' => $user->id]);
    }

    /** [{type_id?, name, amount}] - a type from the list here gives its name, and its usual amount when none is given. */
    private function allowances(Territory $place, array $rows): array
    {
        $out = [];
        foreach (array_values($rows) as $i => $a) {
            $type = ! empty($a['type_id']) ? $this->lists->rows($place, 'allowance')->firstWhere('id', (int) $a['type_id']) : null;
            if (! empty($a['type_id']) && ! $type) {
                throw ValidationException::withMessages(["allowances.{$i}.type_id" => ['Pick an allowance used here.']]);
            }
            $label = trim((string) ($a['name'] ?? '')) ?: (string) $type?->name;
            $amount = isset($a['amount']) && $a['amount'] !== '' && $a['amount'] !== null ? round((float) $a['amount'], 2)
                : (float) ($type ? ($this->lists->effective($place, 'allowance', $type)['values']['default_amount'] ?? 0) : 0);
            if ($label === '' && $amount <= 0) {
                continue;
            }
            if ($label === '' || $amount <= 0) {
                throw ValidationException::withMessages(["allowances.{$i}" => ['Give each allowance a name and an amount.']]);
            }
            $out[] = array_filter(['type_id' => $type?->id, 'name' => mb_substr($label, 0, 60), 'amount' => $amount], fn ($v) => $v !== null);
        }

        return $out;
    }

    private function range($grade): string
    {
        $f = fn ($v) => 'KES '.number_format((float) $v, 0);

        return match (true) {
            $grade->min_pay !== null && $grade->max_pay !== null => $f($grade->min_pay).' to '.$f($grade->max_pay),
            $grade->min_pay !== null => 'at least '.$f($grade->min_pay),
            default => 'up to '.$f($grade->max_pay),
        };
    }

    private function clean(?string $s, int $max): ?string
    {
        $s = trim((string) $s);

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    /** @return Collection<int, Territory> places someone can be transferred to by this user */
    public function transferPlaces(User $user): Collection
    {
        $acting = PlaceAccess::acting($user);
        if (! $acting) {
            return collect();
        }

        return Territory::whereIn('id', [(int) $acting->id, ...PlaceAccess::descendantIds($acting)])->whereIn('territory_type', ['church', 'region', 'diocese'])->orderBy('name')->get();
    }
}
