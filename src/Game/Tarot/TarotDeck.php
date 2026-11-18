<?php

declare(strict_types=1);

namespace App\Game\Tarot;

use Random\Randomizer;

final class TarotDeck
{
    /**
     * Les 78 cartes : 56 de couleur, 21 atouts et l'Excuse.
     *
     * @return list<TarotCard>
     */
    public static function cards(): array
    {
        $cards = [];

        foreach (TarotSuit::colours() as $suit) {
            foreach (range(1, 14) as $rank) {
                $cards[] = new TarotCard($suit, $rank);
            }
        }

        foreach (range(1, 21) as $rank) {
            $cards[] = new TarotCard(TarotSuit::Trumps, $rank);
        }

        $cards[] = new TarotCard(TarotSuit::Excuse, 0);

        return $cards;
    }

    /**
     * @return list<TarotCard>
     */
    public static function shuffled(Randomizer $randomizer): array
    {
        return $randomizer->shuffleArray(self::cards());
    }
}
