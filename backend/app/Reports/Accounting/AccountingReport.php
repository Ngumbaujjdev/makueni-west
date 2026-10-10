<?php

namespace App\Reports\Accounting;

use App\Enums\TerritoryType;
use App\Models\Territory;
use App\Models\User;
use App\Reports\Report;
use App\Reports\ReportContext;
use App\Support\AccountingAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * What every Accounting PDF shares (docs/specs/accounting-spec.md, Redesign
 * R4): any level, for whoever reads the place's books, money in full, the
 * date range they ask for (this month when none), and the place's own logo
 * for a cover.
 */
abstract class AccountingReport extends Report
{
    public function module(): string
    {
        return 'accounting';
    }

    public function scopes(): array
    {
        return [TerritoryType::CHURCH, TerritoryType::SUBREGION, TerritoryType::REGION, TerritoryType::DIOCESE];
    }

    public function authorize(User $user, Territory $territory): ?string
    {
        return AccountingAccess::canRead($user, $territory) ? null : 'Only those who keep or read these books can export them.';
    }

    protected function money(float|int|string|null $n): string
    {
        $v = (float) $n;

        return ($v < 0 ? '-' : '').'KES '.number_format(abs($v), 2);
    }

    /** @return array{0: string, 1: string} the range asked for, or this month to today */
    protected function dates(ReportContext $c): array
    {
        $to = $c->param('date_to') ?: CarbonImmutable::today()->toDateString();
        $from = $c->param('date_from') ?: CarbonImmutable::parse($to)->startOfMonth()->toDateString();

        return [$from, $to];
    }

    protected function rangeLabel(string $from, string $to): string
    {
        $f = CarbonImmutable::parse($from);
        $t = CarbonImmutable::parse($to);

        return $f->isSameDay($t) ? $f->format('j M Y') : $f->format('j M Y').' to '.$t->format('j M Y');
    }

    protected function day(?string $date): string
    {
        return $date ? CarbonImmutable::parse($date)->format('j M Y') : '-';
    }

    /**
     * The place's own logo (Settings > Profile, stored as WebP) as a PNG the
     * PDF can draw, or null for the diocese's.
     */
    protected function placeLogo(Territory $place): ?string
    {
        if (! $place->logo_path || ! Storage::disk('local')->exists($place->logo_path) || ! function_exists('imagecreatefromwebp')) {
            return null;
        }
        $png = storage_path("app/tmp/place-logo-{$place->id}-".Storage::disk('local')->lastModified($place->logo_path).'.png');
        if (! is_file($png)) {
            @mkdir(dirname($png), 0775, true);
            $img = @imagecreatefromwebp(Storage::disk('local')->path($place->logo_path));
            if (! $img) {
                return null;
            }
            imagesavealpha($img, true);
            imagepng($img, $png);
            imagedestroy($img);
        }

        return $png;
    }
}
