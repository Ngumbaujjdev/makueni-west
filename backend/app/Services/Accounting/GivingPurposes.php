<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingFund;
use App\Models\GivingPurpose;
use App\Models\Setting;
use App\Models\Territory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
            $this->rows = GivingPurpose::orderByRaw('territory_id is not null')->orderBy('display_order')->orderBy('id')->get()->keyBy('key');
            $this->readAt = time();
        }

        return $this->rows;
    }

    /** After a change, read the list again. */
    public function forget(): void
    {
        $this->rows = null;
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
            'icon' => $p->icon, 'colour' => $p->colour])->values()->all();
    }
}
