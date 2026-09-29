<?php

namespace App\Reports;

use App\Enums\TerritoryType;
use App\Models\Territory;
use App\Models\User;
use App\Services\DemographicsGrowthService;

/**
 * Who a report is about and for whom. Every report reads its scope from
 * here - the territory, its level, the churches it covers - so the same
 * report class serves a church today and a region or diocese later.
 */
final class ReportContext
{
    /** @var int[] */
    private ?array $churchIds = null;

    public function __construct(
        public Territory $territory,
        public User $user,
        public array $params = [],
    ) {}

    public function type(): TerritoryType
    {
        return $this->territory->territory_type;
    }

    public function isChurch(): bool
    {
        return $this->type() === TerritoryType::CHURCH;
    }

    /** "Church Holy Communion report", "Regional demographics report"... - the line above the title. */
    public function kicker(string $subject): string
    {
        $scope = match ($this->type()) {
            TerritoryType::CHURCH => 'Church',
            TerritoryType::SUBREGION => 'Subregional',
            TerritoryType::REGION => 'Regional',
            TerritoryType::DIOCESE => 'Diocesan',
            default => 'Diocesan',
        };

        return "{$scope} {$subject}";
    }

    /** "St Paul's · Kathonzweni Subregion · Makueni Region" - nearest first, stopping before the diocese. */
    public function scopeLabel(): string
    {
        return $this->territory->getAncestry()
            ->reverse()
            ->reject(fn (Territory $t) => in_array($t->territory_type, [TerritoryType::GLOBAL, TerritoryType::DIOCESE], true) && $t->id !== $this->territory->id)
            ->map(function (Territory $t) {
                $type = $t->territory_type?->getDisplayName() ?? '';
                $named = $t->id === $this->territory->id || $t->territory_type === TerritoryType::CHURCH
                    || str_ends_with(strtolower($t->name), strtolower($type));

                return $named ? $t->name : trim("{$t->name} {$type}");
            })
            ->implode(' · ');
    }

    /** @return int[] the churches this report covers */
    public function churchIds(): array
    {
        return $this->churchIds ??= $this->isChurch()
            ? [$this->territory->id]
            : app(DemographicsGrowthService::class)->descendantChurchIds($this->territory);
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function preparedBy(): string
    {
        return $this->user->full_name ?: ($this->user->username ?? 'System');
    }
}
