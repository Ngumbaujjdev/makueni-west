<?php

namespace App\Enums;

enum AssignmentType: string
{
    case PRIMARY = 'primary';
    case SECONDARY = 'secondary';
    case TEMPORARY = 'temporary';
    case ACTING = 'acting';

    public function getDisplayName(): string
    {
        return match ($this) {
            self::PRIMARY => 'Primary Assignment',
            self::SECONDARY => 'Secondary Assignment',
            self::TEMPORARY => 'Temporary Assignment',
            self::ACTING => 'Acting Assignment',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::PRIMARY => 'Main role and responsibility area',
            self::SECONDARY => 'Additional responsibilities in another territory',
            self::TEMPORARY => 'Short-term assignment with expiry date',
            self::ACTING => 'Temporary role while permanent assignee is unavailable',
        };
    }

    public static function getSelectOptions(): array
    {
        return collect(self::cases())->map(function ($case) {
            return [
                'value' => $case->value,
                'label' => $case->getDisplayName(),
                'description' => $case->getDescription(),
            ];
        })->toArray();
    }
}
