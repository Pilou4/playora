<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Tapis des tables de jeu, choisi par l'admin. La valeur sert de classe CSS (belote--lagon).
 */
enum Carpet: string
{
    case Lagon = 'lagon';
    case Forest = 'vert';
    case Night = 'nuit';

    public function label(): string
    {
        return match ($this) {
            self::Lagon => 'Lagon',
            self::Forest => 'Forêt',
            self::Night => 'Nuit',
        };
    }
}
