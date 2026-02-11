<?php

namespace App\Enums;

enum TerritoryType: string
{
    case GLOBAL = 'global';
    case DIOCESE = 'diocese';
    case REGION = 'region';
    case SUBREGION = 'subregion';
    case CHURCH = 'church';

    public function getLevel(): int
    {
        return match ($this) {
            self::GLOBAL => 0,
            self::DIOCESE => 1,
            self::REGION => 2,
            self::SUBREGION => 3,
            self::CHURCH => 4,
        };
    }

    public function getDisplayName(): string
    {
        return match ($this) {
            self::GLOBAL => 'Global Organization',
            self::DIOCESE => 'Diocese',
            self::REGION => 'Region',
            self::SUBREGION => 'Subregion',
            self::CHURCH => 'Church',
        };
    }

    public function canHaveChildren(): bool
    {
        return match ($this) {
            self::GLOBAL => true,
            self::DIOCESE => true,
            self::REGION => true,
            self::SUBREGION => true,
            self::CHURCH => false,
        };
    }

    public static function getSelectOptions(): array
    {
        return collect(self::cases())->map(function ($case) {
            return [
                'value' => $case->value,
                'label' => $case->getDisplayName(),
                'level' => $case->getLevel(),
                'can_have_children' => $case->canHaveChildren(),
            ];
        })->toArray();
    }
}
