<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingFund;
use App\Models\GivingPurpose;
use App\Models\Setting;
use App\Models\Territory;
use App\Models\User;
use App\Support\PlaceAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Giving options (docs/specs/accounting-spec.md, A11) - the one list the giving
 * page, the Pay Bill account numbers, Ask to pay, claims and every receipt read.
 *
 * The five that used to be fixed in code are made on first use with the same
 * keys, endings and words, so nothing already given changes name or account.
 */
final class GivingPurposes
{
    /** key => [label, account code, fund code (null = General), ending, other words, icon, colour] */
    public const STANDARD = [
        'T' => ['Tithe', '4000', null, 'T', ['TITHE', 'TITHES'], 'ri-hand-heart-line', 'primary'],
        'O' => ['Offering', '4010', null, 'OFF', ['O', 'OFFERING', 'SADAKA'], 'ri-gift-line', 'success'],
        'TH' => ['Thanksgiving', '4020', null, 'TH', ['THANKS', 'THANKSGIVING'], 'ri-star-smile-line', 'pink'],
        'B' => ['Building fund', '4020', 'BLD', 'B', ['BLD', 'BUILDING'], 'ri-building-2-line', 'warning'],
        'K' => ['KYS', '4020', 'KYS', 'KYS', ['K'], 'ri-group-line', 'purple'],
    ];

    /** The pictures an option can carry. */
    public const ICONS = ['ri-hand-heart-line', 'ri-gift-line', 'ri-star-smile-line', 'ri-building-2-line', 'ri-group-line', 'ri-heart-line', 'ri-hand-coin-line',
        'ri-community-line', 'ri-earth-line', 'ri-book-open-line', 'ri-music-2-line', 'ri-graduation-cap-line', 'ri-plant-line', 'ri-calendar-event-line', 'ri-home-heart-line', 'ri-service-line'];

    public const COLOURS = ['primary', 'success', 'purple', 'pink', 'warning', 'danger', 'info', 'secondary'];

    public const LIMIT = 20;

    private const LEVELS = ['diocese' => 0, 'region' => 1, 'subregion' => 2, 'church' => 3];

    /** @var array<int, int[]> place id => the places above it */
    private array $above = [];

    /** @var Collection<string, GivingPurpose>|null every option by key, switched-off ones too */
    private ?Collection $rows = null;

    /** When the list was read - a long-running worker reads it again after a minute. */
    private int $readAt = 0;

    public function __construct(private Chart $chart) {}

    /** Make the standard five once (the chart's accounts and funds first). */
    public function ensureStandard(): void
    {
        if (GivingPurpose::whereIn('key', array_keys(self::STANDARD))->count() >= count(self::STANDARD)) {
            return;
        }
        $this->chart->ensureStandard();
        // Whatever the diocese had chosen for "no ending typed" stays the default.
        $saved = json_decode((string) Setting::where('key', 'paybill.default_purpose')->value('value'), true);
        $default = is_string($saved) && isset(self::STANDARD[$saved]) ? $saved : 'O';
        DB::transaction(function () use ($default) {
            $order = 0;
            foreach (self::STANDARD as $key => [$label, $account, $fund, $suffix, $words, $icon, $colour]) {
                GivingPurpose::firstOrCreate(['key' => $key], [
                    'territory_id' => null, 'reach' => 'below', 'label' => $label, 'suffix' => $suffix, 'words' => $words,
                    'account_id' => AccountingAccount::whereNull('territory_id')->where('code', $account)->value('id'),
                    'fund_id' => $fund ? AccountingFund::where('code', $fund)->value('id') : null,
                    'icon' => $icon, 'colour' => $colour, 'display_order' => $order += 10, 'is_active' => true, 'is_default' => $key === $default,
                ]);
            }
        });
        $this->forget();
    }

    /** @return Collection<string, GivingPurpose> */
    public function all(): Collection
    {
        if ($this->rows === null || time() - $this->readAt > 60) {
            $this->ensureStandard();
            $this->rows = GivingPurpose::with('owner:id,name,territory_type')->get()
                ->sortBy(fn (GivingPurpose $p) => sprintf('%d%d%06d%08d', $p->territory_id ? 1 : 0, self::LEVELS[$p->owner?->territory_type?->value] ?? 0, $p->display_order, $p->id))
                ->keyBy('key');
            $this->readAt = time();
        }

        return $this->rows;
    }

    /** After a change, read the list again. */
    public function forget(): void
    {
        $this->rows = null;
        $this->above = [];
    }

    // ------------------------------------------------------------ who sees what, and whose money

    /** Standard ones everywhere; a place's own; and those set up above it for the places below. */
    public function visibleAt(GivingPurpose $p, Territory $place): bool
    {
        if ($p->territory_id === null || (int) $p->territory_id === (int) $place->id) {
            return true;
        }

        return $p->reach === 'below' && in_array((int) $p->territory_id, $this->above[(int) $place->id] ??= array_map(fn ($t) => (int) $t->id, PlaceAccess::ancestors($place)), true);
    }

    /** Switched on, and one this place's givers may give for. */
    public function usableAt(?string $key, Territory $place): bool
    {
        $p = $this->find($key);

        return $p !== null && $p->is_active && $this->visibleAt($p, $place);
    }

    /** Inherited options a place hides from its own giving page (a payment with the ending still posts). @return int[] */
    public function hidden(Territory $place): array
    {
        return array_map('intval', (array) ($place->metadata['giving_hidden'] ?? []));
    }

    /** What a place's giving page and account numbers offer, in order. @return Collection<int, GivingPurpose> */
    public function forPlace(Territory $place, bool $withHidden = false): Collection
    {
        $hidden = $withHidden ? [] : $this->hidden($place);

        return $this->active()->filter(fn (GivingPurpose $p) => $this->visibleAt($p, $place) && ! in_array((int) $p->id, $hidden, true))->values();
    }

    /** The options that are the place's own money - what its own paybill takes (A10b). @return Collection<int, GivingPurpose> */
    public function ownFor(Territory $place): Collection
    {
        return $this->forPlace($place)->filter(fn (GivingPurpose $p) => (int) $this->booksFor($p->key, $place)->id === (int) $place->id)->values();
    }

    /** Whose books a gift for this option collected at a place goes into: the place's own for a standard one, else the owner's. */
    public function booksFor(string $key, Territory $collectedAt): Territory
    {
        $p = $this->find($key);

        return $p && $p->territory_id && (int) $p->territory_id !== (int) $collectedAt->id ? Territory::findOrFail($p->territory_id) : $collectedAt;
    }

    /** Gifts, payments and claims made for it - one that has any is switched off, never removed. */
    public function inUse(GivingPurpose $p): int
    {
        return DB::table('gifts')->where('purpose', $p->key)->count() + DB::table('mpesa_payments')->where('purpose', $p->key)->count()
            + DB::table('payment_claims')->where('purpose', $p->key)->count();
    }

    public function find(?string $key): ?GivingPurpose
    {
        return $key === null || $key === '' ? null : $this->all()->get($key);
    }

    /** Switched on and known - what a giver may pick. */
    public function usable(?string $key): bool
    {
        return (bool) $this->find($key)?->is_active;
    }

    /** An option's name - switched-off ones too, so old gifts keep theirs. */
    public static function label(?string $key, ?string $fallback = null): ?string
    {
        return app(self::class)->find($key)?->label ?? (self::STANDARD[$key ?? ''][0] ?? $fallback);
    }

    /** The options givers can pick, in order. @return Collection<int, GivingPurpose> */
    public function active(): Collection
    {
        return $this->all()->filter(fn (GivingPurpose $p) => $p->is_active)->values();
    }

    /** The option when no ending is typed. */
    public function default(): GivingPurpose
    {
        $all = $this->all();

        return $all->first(fn (GivingPurpose $p) => $p->is_default && $p->is_active) ?? $all->get('O') ?? $all->first();
    }

    /** What a typed ending means: an option's ending first, then its other words. Switched-off options still match, so a printed number keeps working. */
    public function byWord(string $typed): ?GivingPurpose
    {
        $typed = strtoupper($typed);
        $all = $this->all();

        return $all->first(fn (GivingPurpose $p) => $p->suffix === $typed)
            ?? $all->first(fn (GivingPurpose $p) => in_array($typed, (array) $p->words, true));
    }

    /** The account and fund a gift for this option is booked to. @return array{0: AccountingAccount, 1: ?AccountingFund} */
    public function target(string $key): array
    {
        $p = $this->find($key) ?? $this->default();

        return [AccountingAccount::findOrFail($p->account_id), $p->fund_id ? AccountingFund::find($p->fund_id) : null];
    }

    /** What a place's members type for this option (SHR027T). */
    public function accountNumber(Territory $place, string $key): string
    {
        return Paybill::code($place).($this->find($key)?->suffix ?? '');
    }

    /** The options as the pages show them. @return array<int, array{key: string, label: string, suffix: string, icon: ?string, colour: ?string}> */
    public function present(?Collection $rows = null): array
    {
        return ($rows ?? $this->active())->map(fn (GivingPurpose $p) => ['key' => $p->key, 'label' => $p->label, 'suffix' => $p->suffix,
            'icon' => $p->icon, 'colour' => $p->colour, 'owner' => $p->owner ? ['id' => $p->owner->id, 'name' => $p->owner->name, 'level' => $p->owner->territory_type->value] : null])->values()->all();
    }

    // ------------------------------------------------------------ saving a place's list

    /**
     * Save the options a place owns (or, with no owner, the standard ones the
     * diocese keeps): rows by id are changed, new ones added, and any left out
     * removed - or switched off when they have been used. The standard five
     * are only ever switched off.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function save(?Territory $owner, array $rows, ?User $by, ?string $default = null): void
    {
        $this->ensureStandard();
        if (count($rows) > self::LIMIT) {
            throw ValidationException::withMessages(['purposes' => ['At most '.self::LIMIT.' giving options for a place.']]);
        }
        $mine = GivingPurpose::where('territory_id', $owner?->id)->get()->keyBy('id');
        $others = GivingPurpose::where(fn ($q) => $owner ? $q->whereNull('territory_id')->orWhere('territory_id', '!=', $owner->id) : $q->whereNotNull('territory_id'))->get();
        // Every ending and word another option already answers to.
        $taken = [];
        foreach ($others as $o) {
            foreach ([$o->suffix, ...(array) $o->words] as $w) {
                $taken[strtoupper((string) $w)] = $o->label;
            }
        }
        $codes = app(Paybill::class)->codes();
        $letterCodes = array_filter(array_keys($codes), fn ($c) => ctype_alpha((string) $c));
        $funds = $owner ? app(Funds::class)->usableIds($owner) : \App\Models\AccountingFund::whereNull('territory_id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $church = $owner?->territory_type->value === 'church';
        $seen = [];
        $words = [];
        DB::transaction(function () use ($rows, $owner, $by, $mine, $taken, $codes, $letterCodes, $funds, $church, &$seen, &$words) {
            foreach (array_values($rows) as $i => $r) {
                $label = trim((string) ($r['label'] ?? ''));
                $suffix = strtoupper(preg_replace('/\s+/', '', (string) ($r['suffix'] ?? '')));
                $list = collect((array) ($r['words'] ?? []))->map(fn ($w) => strtoupper(preg_replace('/\s+/', '', (string) $w)))->filter()->unique()->values()->all();
                $row = ! empty($r['id']) ? ($mine[(int) $r['id']] ?? null) : null;
                if (! empty($r['id']) && ! $row) {
                    throw ValidationException::withMessages(["purposes.{$i}" => ['That option isn\'t one of this place\'s.']]);
                }
                if ($row && $row->suffix !== $suffix && ! in_array($row->suffix, $list, true)) {
                    // A changed ending keeps the old one, so numbers already printed still work.
                    $list[] = $row->suffix;
                }
                if (mb_strlen($label) < 2 || mb_strlen($label) > 40) {
                    throw ValidationException::withMessages(["purposes.{$i}.label" => ['Give the option a name (up to 40 letters).']]);
                }
                if (! preg_match('/^[A-Z]{1,6}$/', $suffix)) {
                    throw ValidationException::withMessages(["purposes.{$i}.suffix" => ['The ending is 1 to 6 letters, e.g. CONF.']]);
                }
                foreach ($list as $w) {
                    if (! preg_match('/^[A-Z]{1,12}$/', $w)) {
                        throw ValidationException::withMessages(["purposes.{$i}.words" => ['Other words are letters only, up to 12 each.']]);
                    }
                }
                foreach ([$suffix, ...$list] as $w) {
                    if (str_ends_with($w, 'DS')) {
                        throw ValidationException::withMessages(["purposes.{$i}.suffix" => ["{$w} ends in DS, which marks a share payment - pick another."]]);
                    }
                    if (isset($taken[$w]) || isset($words[$w])) {
                        throw ValidationException::withMessages(["purposes.{$i}.suffix" => ["{$w} is already used by ".($taken[$w] ?? $words[$w]).' - pick another.']]);
                    }
                    foreach ($letterCodes as $c) {
                        if (isset($codes[$c.$w])) {
                            throw ValidationException::withMessages(["purposes.{$i}.suffix" => ["{$c}{$w} is another place's code - pick another ending."]]);
                        }
                    }
                    $words[$w] = $label;
                }
                $account = AccountingAccount::whereKey((int) ($r['account_id'] ?? 0))->whereNull('territory_id')->where('type', 'income')->where('is_header', false)->where('is_active', true)->first();
                if (! $account) {
                    throw ValidationException::withMessages(["purposes.{$i}.account_id" => ['Pick the income account it is booked to.']]);
                }
                $fundId = ! empty($r['fund_id']) ? (int) $r['fund_id'] : null;
                if ($fundId !== null && ! in_array($fundId, $funds, true)) {
                    throw ValidationException::withMessages(["purposes.{$i}.fund_id" => ['Pick one of this place\'s funds.']]);
                }
                $fields = ['label' => $label, 'suffix' => $suffix, 'words' => array_values(array_diff($list, [$suffix])) ?: null, 'account_id' => $account->id, 'fund_id' => $fundId,
                    'reach' => ! $owner ? 'below' : ($church ? 'self' : (($r['reach'] ?? 'self') === 'below' ? 'below' : 'self')),
                    'icon' => in_array($r['icon'] ?? null, self::ICONS, true) ? $r['icon'] : 'ri-hand-heart-line',
                    'colour' => in_array($r['colour'] ?? null, self::COLOURS, true) ? $r['colour'] : 'primary',
                    'display_order' => ($i + 1) * 10, 'is_active' => (bool) ($r['is_active'] ?? true), 'updated_by' => $by?->id];
                if ($row) {
                    $row->update($fields);
                } else {
                    $key = $suffix;
                    for ($n = 2; GivingPurpose::where('key', $key)->exists(); $n++) {
                        $key = mb_substr($suffix, 0, 6).$n;
                    }
                    $row = GivingPurpose::create($fields + ['territory_id' => $owner?->id, 'key' => $key, 'created_by' => $by?->id]);
                }
                $seen[] = $row->id;
            }
            foreach ($mine->except($seen) as $gone) {
                isset(self::STANDARD[$gone->key]) || $this->inUse($gone) > 0 ? $gone->update(['is_active' => false]) : $gone->delete();
            }
        });
        if (! $owner && $default !== null) {
            $pick = GivingPurpose::whereNull('territory_id')->where('key', $default)->where('is_active', true)->first();
            if (! $pick) {
                throw ValidationException::withMessages(['default' => ['The option for "no ending typed" must be a standard one that is switched on.']]);
            }
            GivingPurpose::whereNull('territory_id')->update(['is_default' => false]);
            $pick->update(['is_default' => true]);
        }
        $this->forget();
    }

    /** Hide inherited options from a place's own giving page. @param int[] $ids */
    public function hide(Territory $place, array $ids): void
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter(fn ($id) => ($p = $this->all()->firstWhere('id', $id)) && (int) $p->territory_id !== (int) $place->id && ! $p->is_default)->values()->all();
        $metadata = $place->metadata ?? [];
        $metadata['giving_hidden'] = $ids;
        $place->metadata = $metadata;
        Territory::withoutAuditing(fn () => $place->save());
    }
}
