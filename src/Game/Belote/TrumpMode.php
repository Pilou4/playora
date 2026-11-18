<?php

declare(strict_types=1);

namespace App\Game\Belote;

/**
 * Le contrat se joue à une couleur d'atout, ou (coinche seulement) en tout atout ou en sans atout.
 */
enum TrumpMode: string
{
    case Suit = 'couleur';
    case AllTrumps = 'tout-atout';
    case NoTrumps = 'sans-atout';

    public function label(): string
    {
        return match ($this) {
            self::Suit => 'Couleur',
            self::AllTrumps => 'Tout atout',
            self::NoTrumps => 'Sans atout',
        };
    }

    /** Abréviation affichée à côté du contrat (TA, SA). */
    public function short(): string
    {
        return match ($this) {
            self::Suit => '',
            self::AllTrumps => 'TA',
            self::NoTrumps => 'SA',
        };
    }
}
