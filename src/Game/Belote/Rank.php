<?php

declare(strict_types=1);

namespace App\Game\Belote;

/**
 * Les 8 hauteurs du jeu de 32 cartes, avec leur ordre et leur valeur à l'atout et hors atout.
 */
enum Rank: string
{
    case Seven = '7';
    case Eight = '8';
    case Nine = '9';
    case Ten = '10';
    case Jack = 'valet';
    case Queen = 'dame';
    case King = 'roi';
    case Ace = 'as';

    /** Force à l'atout : Valet, 9, As, 10, Roi, Dame, 8, 7. */
    public function trumpStrength(): int
    {
        return match ($this) {
            self::Seven => 0, self::Eight => 1, self::Queen => 2, self::King => 3,
            self::Ten => 4, self::Ace => 5, self::Nine => 6, self::Jack => 7,
        };
    }

    /** Force hors atout : As, 10, Roi, Dame, Valet, 9, 8, 7. */
    public function plainStrength(): int
    {
        return match ($this) {
            self::Seven => 0, self::Eight => 1, self::Nine => 2, self::Jack => 3,
            self::Queen => 4, self::King => 5, self::Ten => 6, self::Ace => 7,
        };
    }

    public function trumpPoints(): int
    {
        return match ($this) {
            self::Jack => 20, self::Nine => 14, self::Ace => 11, self::Ten => 10,
            self::King => 4, self::Queen => 3, self::Eight, self::Seven => 0,
        };
    }

    public function plainPoints(): int
    {
        return match ($this) {
            self::Ace => 11, self::Ten => 10, self::King => 4, self::Queen => 3,
            self::Jack => 2, self::Nine, self::Eight, self::Seven => 0,
        };
    }
}
