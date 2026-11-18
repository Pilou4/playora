<?php

declare(strict_types=1);

namespace App\Game\Belote;

/**
 * Coinche en tout atout (TA) ou en sans atout (SA), règles GameDuell.
 *
 * Tout atout : chaque couleur se joue comme l'atout (Valet, 9, As, 10, Roi, Dame, 8, 7) ;
 * Valet 14, 9 9, As 6, 10 5, Roi 3, Dame 1. On fournit la couleur demandée en montant si possible.
 * Sans atout : As 19, 10 10, Roi 4, Dame 3, Valet 2 (ordre As, 10, Roi, Dame, Valet, 9, 8, 7) ;
 * on fournit la couleur demandée, sans obligation de monter.
 * Sans la couleur demandée : on joue ce qu'on veut (il n'y a pas d'atout pour couper).
 * Dans les deux cas, une donne vaut 162 points avec le dix de der.
 */
final class ModeRules
{
    public static function points(Card $card, TrumpMode $mode): int
    {
        return match ($mode) {
            TrumpMode::AllTrumps => match ($card->rank) {
                Rank::Jack => 14, Rank::Nine => 9, Rank::Ace => 6, Rank::Ten => 5, Rank::King => 3, Rank::Queen => 1, default => 0,
            },
            TrumpMode::NoTrumps => match ($card->rank) {
                Rank::Ace => 19, Rank::Ten => 10, Rank::King => 4, Rank::Queen => 3, Rank::Jack => 2, default => 0,
            },
            TrumpMode::Suit => throw new \LogicException('Contrat à la couleur : voir BeloteRules.'),
        };
    }

    public static function strength(Card $card, TrumpMode $mode): int
    {
        return TrumpMode::AllTrumps === $mode ? $card->rank->trumpStrength() : $card->rank->plainStrength();
    }

    /**
     * @param list<Card>      $hand
     * @param list<TrickCard> $trick
     *
     * @return list<Card>
     */
    public static function playableCards(array $hand, array $trick, TrumpMode $mode): array
    {
        if ([] === $trick) {
            return $hand;
        }

        $sameSuit = BeloteRules::ofSuit($hand, $trick[0]->card->suit);

        if ([] === $sameSuit) {
            return $hand;
        }

        if (TrumpMode::NoTrumps === $mode) {
            return $sameSuit;
        }

        // Tout atout : monter sur la plus forte carte de la couleur demandée si possible
        $best = self::strength(self::best($trick, $mode)->card, $mode);
        $higher = array_values(array_filter($sameSuit, static fn (Card $card): bool => self::strength($card, $mode) > $best));

        return [] === $higher ? $sameSuit : $higher;
    }

    /**
     * @param non-empty-list<TrickCard> $trick
     */
    public static function winner(array $trick, TrumpMode $mode): int
    {
        return self::best($trick, $mode)->position;
    }

    /**
     * @param list<TrickCard> $trick
     */
    public static function trickPoints(array $trick, TrumpMode $mode): int
    {
        return array_sum(array_map(static fn (TrickCard $played): int => self::points($played->card, $mode), $trick));
    }

    /**
     * La plus forte carte de la couleur demandée.
     *
     * @param non-empty-list<TrickCard> $trick
     */
    private static function best(array $trick, TrumpMode $mode): TrickCard
    {
        $led = $trick[0]->card->suit;
        $best = $trick[0];

        foreach ($trick as $played) {
            if ($played->card->suit === $led && self::strength($played->card, $mode) > self::strength($best->card, $mode)) {
                $best = $played;
            }
        }

        return $best;
    }
}
