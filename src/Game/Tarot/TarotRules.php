<?php

declare(strict_types=1);

namespace App\Game\Tarot;

/**
 * Règles du jeu de la carte (Fédération française de tarot) :
 * - à la couleur, on fournit (sans obligation de monter) ;
 * - sans la couleur, on coupe ; si un atout est déjà posé, on surcoupe, sinon on pisse (atout plus petit) ;
 * - à l'atout, on monte si on peut ;
 * - sans couleur ni atout, on joue ce qu'on veut ;
 * - l'Excuse se joue à tout moment ; si elle ouvre le pli, la carte suivante donne la couleur.
 */
final class TarotRules
{
    /** Nombres de joueurs possibles. */
    public const array PLAYER_COUNTS = [3, 4, 5];

    public static function next(int $position, int $players = 4): int
    {
        return ($position + 1) % $players;
    }

    /** Cartes de chaque joueur : 24 à 3, 18 à 4, 15 à 5. */
    public static function handSize(int $players): int
    {
        return match ($players) {
            3 => 24,
            5 => 15,
            default => 18,
        };
    }

    /** Cartes du chien : 6 à 3 et à 4, 3 à 5. */
    public static function dogSize(int $players): int
    {
        return 5 === $players ? 3 : 6;
    }

    /**
     * @param list<TarotCard> $hand
     * @param list<TrickCard> $trick
     *
     * @return list<TarotCard>
     */
    public static function playableCards(array $hand, array $trick): array
    {
        $led = self::ledSuit($trick);

        if (null === $led) {
            return $hand;
        }

        $excuse = array_values(array_filter($hand, static fn (TarotCard $card): bool => $card->isExcuse()));
        $trumps = self::ofSuit($hand, TarotSuit::Trumps);

        if ($led->isColour()) {
            $same = self::ofSuit($hand, $led);

            if ([] !== $same) {
                return [...$same, ...$excuse];
            }
        }

        if ([] === $trumps) {
            return $hand;
        }

        // Monter sur le plus fort atout du pli si possible, sinon n'importe quel atout
        $highest = max(0, ...array_map(static fn (TrickCard $played): int => $played->card->isTrump() ? $played->card->rank : 0, $trick));
        $higher = array_values(array_filter($trumps, static fn (TarotCard $card): bool => $card->rank > $highest));

        return [...([] === $higher ? $trumps : $higher), ...$excuse];
    }

    /**
     * @param list<TarotCard> $hand
     * @param list<TrickCard> $trick
     */
    public static function isPlayable(TarotCard $card, array $hand, array $trick): bool
    {
        foreach (self::playableCards($hand, $trick) as $allowed) {
            if ($allowed->equals($card)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Couleur demandée : celle de la première carte qui n'est pas l'Excuse (null si aucune).
     *
     * @param list<TrickCard> $trick
     */
    public static function ledSuit(array $trick): ?TarotSuit
    {
        foreach ($trick as $played) {
            if (!$played->card->isExcuse()) {
                return $played->card->suit;
            }
        }

        return null;
    }

    /**
     * Place qui remporte le pli : le plus fort atout, sinon la plus forte carte de la couleur demandée.
     * L'Excuse ne gagne jamais (sauf au dernier pli d'un chelem : voir TarotEngine).
     *
     * @param non-empty-list<TrickCard> $trick
     */
    public static function winner(array $trick): int
    {
        $led = self::ledSuit($trick);
        $best = null;

        foreach ($trick as $played) {
            $card = $played->card;

            if ($card->isExcuse() || ($card->suit !== $led && !$card->isTrump())) {
                continue;
            }

            if (null === $best
                || ($card->isTrump() && !$best->card->isTrump())
                || ($card->suit === $best->card->suit && $card->rank > $best->card->rank)) {
                $best = $played;
            }
        }

        return ($best ?? $trick[0])->position;
    }

    /**
     * @param list<TarotCard> $cards
     */
    public static function halfPoints(array $cards): int
    {
        return array_sum(array_map(static fn (TarotCard $card): int => $card->halfPoints(), $cards));
    }

    /**
     * @param list<TarotCard> $cards
     */
    public static function bouts(array $cards): int
    {
        return \count(array_filter($cards, static fn (TarotCard $card): bool => $card->isBout()));
    }

    /** Points à faire selon le nombre de bouts : 56, 51, 41 ou 36. */
    public static function required(int $bouts): int
    {
        return [56, 51, 41, 36][min(3, $bouts)];
    }

    /**
     * @param list<TarotCard> $hand
     *
     * @return list<TarotCard>
     */
    public static function ofSuit(array $hand, TarotSuit $suit): array
    {
        return array_values(array_filter($hand, static fn (TarotCard $card): bool => $card->suit === $suit));
    }

    /**
     * Atouts d'une main pour une poignée : l'Excuse compte comme un atout.
     *
     * @param list<TarotCard> $hand
     */
    public static function poigneeTrumps(array $hand): int
    {
        return \count(array_filter($hand, static fn (TarotCard $card): bool => $card->isTrump() || $card->isExcuse()));
    }

    /**
     * Cartes qu'on peut mettre à l'écart : ni Roi ni bout ; des atouts seulement si on ne peut pas faire autrement.
     *
     * @param list<TarotCard> $hand
     *
     * @return array{list<TarotCard>, list<TarotCard>} [cartes permises, atouts permis en dernier recours]
     */
    public static function discardable(array $hand): array
    {
        $plain = array_values(array_filter($hand, static fn (TarotCard $card): bool => $card->suit->isColour() && !$card->isKing()));
        $trumps = array_values(array_filter($hand, static fn (TarotCard $card): bool => $card->isTrump() && !$card->isBout()));

        return [$plain, $trumps];
    }
}
