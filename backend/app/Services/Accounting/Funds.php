<?php

namespace App\Services\Accounting;

use App\Models\AccountingFund;
use App\Models\Territory;
use App\Models\User;
use App\Support\PlaceAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Funds at every level (docs/specs/accounting-spec.md, A11): the standard ones
 * (General, Building, KYS, Conference) are everyone's; a place adds its own -
 * for itself, or for every place below it. A place fund's balance sits on
 * 3900 Other funds, told apart by the fund on each line.
 */
final class Funds
{
    public const LIMIT = 20;

    /** @var array<int, int[]> place id => usable fund ids */
    private array $usable = [];

    public function __construct(private Chart $chart) {}

    /** The funds a place can use: standard, its own, and those set up above it for the places below. */
    public function forPlace(Territory $place, bool $activeOnly = true): Collection
    {
        $this->chart->ensureStandard();
        $above = array_map(fn ($t) => (int) $t->id, PlaceAccess::ancestors($place));

        return AccountingFund::query()
            ->where(fn ($q) => $q->whereNull('territory_id')->orWhere('territory_id', $place->id)
                ->orWhere(fn ($q) => $q->whereIn('territory_id', $above ?: [0])->where('reach', 'below')))
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderByRaw('territory_id is not null')->orderByRaw("code = 'GEN' DESC")->orderBy('display_order')->orderBy('id')
            ->with('owner:id,name,territory_type')->get();
    }

    /** @return int[] */
    public function usableIds(Territory $place): array
    {
        return $this->usable[(int) $place->id] ??= $this->forPlace($place, false)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function usableAt(?int $fundId, Territory $place): bool
    {
        return $fundId === null || in_array($fundId, $this->usableIds($place), true);
    }

    /** As the pages list them. */
    public function present(Collection $funds, bool $withAbout = false): array
    {
        return $funds->map(fn (AccountingFund $f) => ['id' => $f->id, 'code' => $f->code, 'name' => $f->name, 'is_restricted' => (bool) $f->is_restricted, 'reach' => $f->reach]
            + ($withAbout ? ['description' => $f->description, 'is_active' => (bool) $f->is_active] : [])
            + ['owner' => $f->owner ? ['id' => $f->owner->id, 'name' => $f->owner->name, 'level' => $f->owner->territory_type->value] : null])->values()->all();
    }

    /** How often a fund has been used - one that has is switched off, never removed. */
    public function inUse(AccountingFund $f): int
    {
        $n = 0;
        foreach (['journal_lines', 'collection_lines', 'payment_voucher_lines', 'requisitions', 'purchase_order_lines', 'giving_purposes'] as $table) {
            if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'fund_id')) {
                $n += DB::table($table)->where('fund_id', $f->id)->count();
            }
        }

        return $n;
    }

    /**
     * Save a place's own funds: rows by id are kept and changed, new ones added,
     * and any left out removed - or switched off when they have been used.
     *
     * @param  array<int, array{id?: ?int, code?: string, name?: string, is_restricted?: bool, reach?: string, is_active?: bool}>  $rows
     */
    public function save(Territory $owner, array $rows, ?User $by): Collection
    {
        $this->chart->ensureStandard();
        if (count($rows) > self::LIMIT) {
            throw ValidationException::withMessages(['funds' => ['At most '.self::LIMIT.' funds for a place.']]);
        }
        $standard = AccountingFund::whereNull('territory_id')->pluck('code')->all();
        $equity = $this->chart->account('other_funds');
        $mine = AccountingFund::where('territory_id', $owner->id)->get()->keyBy('id');
        $church = $owner->territory_type->value === 'church';
        $seen = [];
        $codes = [];
        foreach (array_values($rows) as $i => $r) {
            $name = trim((string) ($r['name'] ?? ''));
            $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($r['code'] ?? '')));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
                throw ValidationException::withMessages(["funds.{$i}.name" => ['Give the fund a name.']]);
            }
            if (! preg_match('/^[A-Z0-9]{2,10}$/', $code)) {
                throw ValidationException::withMessages(["funds.{$i}.code" => ['A short code of 2 to 10 letters or numbers, e.g. CHOIR.']]);
            }
            if (in_array($code, $standard, true) || in_array($code, $codes, true)) {
                throw ValidationException::withMessages(["funds.{$i}.code" => ["{$code} is already taken - pick another code."]]);
            }
            $codes[] = $code;
            $fields = ['code' => $code, 'name' => $name, 'is_restricted' => (bool) ($r['is_restricted'] ?? true), 'reach' => $church ? 'self' : (($r['reach'] ?? 'self') === 'below' ? 'below' : 'self'),
                'is_active' => (bool) ($r['is_active'] ?? true), 'display_order' => ($i + 1) * 10];
            $fund = ! empty($r['id']) ? ($mine[(int) $r['id']] ?? null) : null;
            if (! empty($r['id']) && ! $fund) {
                throw ValidationException::withMessages(["funds.{$i}" => ['That fund isn\'t one of this place\'s.']]);
            }
            if ($fund) {
                $fund->update($fields);
            } else {
                $fund = AccountingFund::create($fields + ['territory_id' => $owner->id, 'equity_account_id' => $equity->id, 'description' => null]);
            }
            $seen[] = $fund->id;
        }
        foreach ($mine->except($seen) as $gone) {
            $this->inUse($gone) > 0 ? $gone->update(['is_active' => false]) : $gone->delete();
        }
        $this->usable = [];

        return $this->forPlace($owner, false)->where('territory_id', $owner->id)->values();
    }
}
