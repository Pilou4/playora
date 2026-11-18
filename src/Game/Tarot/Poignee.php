<?php

declare(strict_types=1);

namespace App\Game\Tarot;

/**
 * Poignée : des atouts montrés avant de jouer sa première carte (l'Excuse peut remplacer un atout).
 * Le nombre d'atouts dépend du nombre de joueurs.
 * La prime va au camp qui gagne la donne, quel que soit le contrat.
 */
enum Poignee: string
{
    case Simple = 'simple';
    case Double = 'double';
    case Triple = 'triple';

    public function label(): string
    {
        return match ($this) {
            self::Simple => 'Poignée simple',
            self::Double => 'Poignée double',
            self::Triple => 'Poignée triple',
        };
    }

    /** Nombre d'atouts à montrer : 10, 13, 15 à 4 joueurs ; 13, 15, 18 à 3 ; 8, 10, 13 à 5. */
    public function trumps(int $players = 4): int
    {
        return match ($players) {
            3 => match ($this) { self::Simple => 13, self::Double => 15, self::Triple => 18 },
            5 => match ($this) { self::Simple => 8, self::Double => 10, self::Triple => 13 },
            default => match ($this) { self::Simple => 10, self::Double => 13, self::Triple => 15 },
        };
    }

    public function bonus(): int
    {
        return match ($this) {
            self::Simple => 20,
            self::Double => 30,
            self::Triple => 40,
        };
    }
}
