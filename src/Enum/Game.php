<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Jeux proposés. La valeur sert dans l'adresse (/jeux/belote) et en base.
 */
enum Game: string
{
    case Belote = 'belote';

    public function label(): string
    {
        return match ($this) {
            self::Belote => 'Belote',
        };
    }

    public function playerCount(): int
    {
        return match ($this) {
            self::Belote => 4,
        };
    }

    /**
     * @return list<int>
     */
    public function targetPointsChoices(): array
    {
        return match ($this) {
            self::Belote => [501, 1001, 1501],
        };
    }
}
