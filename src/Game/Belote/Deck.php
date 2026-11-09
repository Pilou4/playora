<?php

declare(strict_types=1);

namespace App\Game\Belote;

use Random\Randomizer;

final class Deck
{
    /**
     * Les 32 cartes, dans l'ordre.
     *
     * @return list<Card>
     */
    public static function cards(): array
    {
        $cards = [];

        foreach (Suit::cases() as $suit) {
            foreach (Rank::cases() as $rank) {
                $cards[] = new Card($suit, $rank);
            }
        }

        return $cards;
    }

    /**
     * Les 32 cartes, mélangées.
     *
     * @return list<Card>
     */
    public static function shuffled(Randomizer $randomizer): array
    {
        return $randomizer->shuffleArray(self::cards());
    }
}
