<?php

declare(strict_types=1);

namespace App\Game\Belote;

/**
 * Règles d'un pli : quelles cartes ont le droit d'être jouées, et qui gagne.
 * Places 0 à 3 ; partenaires : 0 et 2, 1 et 3.
 */
final class BeloteRules
{
    public static function team(int $position): int
    {
        return $position % 2;
    }

    public static function next(int $position): int
    {
        return ($position + 1) % 4;
    }

    /**
     * Fournir la couleur demandée ; à l'atout, monter si possible. Sans la couleur :
     * couper (et surcouper si possible, sinon sous-couper), sauf si le partenaire est maître.
     *
     * @param list<Card>      $hand
     * @param list<TrickCard> $trick
     *
     * @return list<Card>
     */
    public static function playableCards(array $hand, array $trick, Suit $trump, int $position): array
    {
        if ([] === $trick) {
            return $hand;
        }

        $led = $trick[0]->card->suit;
        $sameSuit = self::ofSuit($hand, $led);
        $trumps = self::ofSuit($hand, $trump);

        if ($led === $trump) {
            return [] === $trumps ? $hand : self::higherTrumpsOr($trumps, $trick, $trump);
        }

        if ([] !== $sameSuit) {
            return $sameSuit;
        }

        if (self::team(self::winner($trick, $trump)) === self::team($position)) {
            return $hand;
        }

        return [] === $trumps ? $hand : self::higherTrumpsOr($trumps, $trick, $trump);
    }

    /**
     * @param list<Card>      $hand
     * @param list<TrickCard> $trick
     */
    public static function isPlayable(Card $card, array $hand, array $trick, Suit $trump, int $position): bool
    {
        foreach (self::playableCards($hand, $trick, $trump, $position) as $allowed) {
            if ($allowed->equals($card)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Place du joueur qui remporte (pour l'instant) le pli.
     *
     * @param non-empty-list<TrickCard> $trick
     */
    public static function winner(array $trick, Suit $trump): int
    {
        $led = $trick[0]->card->suit;
        $best = $trick[0];

        foreach ($trick as $played) {
            if (self::beats($played->card, $best->card, $led, $trump)) {
                $best = $played;
            }
        }

        return $best->position;
    }

    /**
     * @param list<TrickCard> $trick
     */
    public static function points(array $trick, Suit $trump): int
    {
        return array_sum(array_map(static fn (TrickCard $played): int => $played->card->points($trump), $trick));
    }

    public static function beats(Card $card, Card $best, Suit $led, Suit $trump): bool
    {
        if ($card->isTrump($trump) !== $best->isTrump($trump)) {
            return $card->isTrump($trump);
        }

        if ($card->suit !== $best->suit) {
            return false;
        }

        return $card->strength($trump) > $best->strength($trump) && ($card->suit === $led || $card->isTrump($trump));
    }

    /**
     * @param list<Card> $hand
     *
     * @return list<Card>
     */
    public static function ofSuit(array $hand, Suit $suit): array
    {
        return array_values(array_filter($hand, static fn (Card $card): bool => $card->suit === $suit));
    }

    /**
     * Atouts plus forts que le plus fort du pli ; s'il n'y en a pas, tous les atouts.
     *
     * @param non-empty-list<Card> $trumps
     * @param list<TrickCard>      $trick
     *
     * @return non-empty-list<Card>
     */
    private static function higherTrumpsOr(array $trumps, array $trick, Suit $trump): array
    {
        $highest = -1;

        foreach ($trick as $played) {
            if ($played->card->isTrump($trump)) {
                $highest = max($highest, $played->card->strength($trump));
            }
        }

        $higher = array_values(array_filter($trumps, static fn (Card $card): bool => $card->strength($trump) > $highest));

        return [] === $higher ? $trumps : $higher;
    }
}
