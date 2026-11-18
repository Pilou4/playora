<?php

declare(strict_types=1);

namespace App\Game\Rami;

/**
 * Lecture simple d'une main, pour le robot et pour le coup joué quand le temps est écoulé :
 * les combinaisons qu'on peut y voir, et la carte la moins utile à défausser.
 */
final class RamiHand
{
    /**
     * Des combinaisons posables, trouvées simplement : suites de vraies cartes, puis brelans et carrés,
     * puis des paires complétées par un joker. Chaque carte n'est utilisée qu'une fois.
     *
     * @param list<string> $hand
     *
     * @return list<list<string>>
     */
    public static function melds(array $hand): array
    {
        $left = $hand;
        $groups = [];

        foreach ([self::runs(...), self::sets(...), self::withJokers(...)] as $finder) {
            foreach ($finder($left) as $group) {
                try {
                    RamiMeld::build($group);
                } catch (RamiException) {
                    continue;
                }

                $groups[] = $group;
                $left = array_values(array_diff($left, $group));
            }
        }

        return $groups;
    }

    /**
     * La carte la moins utile : jamais un joker, plutôt une carte hors combinaison et sans voisine, la plus chère.
     *
     * @param list<string> $hand
     */
    public static function worst(array $hand): string
    {
        $used = array_merge([], ...self::melds($hand));
        $free = array_values(array_filter($hand, static fn (string $code): bool => !\in_array($code, $used, true) && !str_starts_with($code, 'joker-')));
        $candidates = [] === $free ? array_values(array_filter($hand, static fn (string $code): bool => !str_starts_with($code, 'joker-'))) : $free;
        $candidates = [] === $candidates ? $hand : $candidates;

        usort($candidates, static fn (string $a, string $b): int => [self::neighbours($hand, $a), -RamiCard::fromCode($a)->penalty()] <=> [self::neighbours($hand, $b), -RamiCard::fromCode($b)->penalty()]);

        return $candidates[0];
    }

    /**
     * Suites de vraies cartes (3 ou plus), couleur par couleur.
     *
     * @param list<string> $hand
     *
     * @return list<list<string>>
     */
    private static function runs(array $hand): array
    {
        $groups = [];

        foreach (RamiCard::SUITS as $suit) {
            $byRank = [];

            foreach ($hand as $code) {
                $card = RamiCard::fromCode($code);

                if ($card->suit === $suit) {
                    $byRank[$card->rank] ??= $code;
                }
            }

            if (isset($byRank[1])) {
                $byRank[14] = $byRank[1];
            }

            $run = [];

            foreach (range(1, 15) as $rank) {
                if (isset($byRank[$rank]) && !\in_array($byRank[$rank], $run, true)) {
                    $run[] = $byRank[$rank];

                    continue;
                }

                if (\count($run) >= 3) {
                    $groups[] = $run;
                }

                $run = isset($byRank[$rank]) ? [$byRank[$rank]] : [];
            }
        }

        return $groups;
    }

    /**
     * Brelans et carrés de vraies cartes.
     *
     * @param list<string> $hand
     *
     * @return list<list<string>>
     */
    private static function sets(array $hand): array
    {
        $byRank = [];

        foreach ($hand as $code) {
            $card = RamiCard::fromCode($code);

            if (!$card->isJoker()) {
                $byRank[$card->rank][(string) $card->suit] ??= $code;
            }
        }

        return array_values(array_map('array_values', array_filter($byRank, static fn (array $suits): bool => \count($suits) >= 3)));
    }

    /**
     * Une paire (même hauteur, ou même couleur à un rang d'écart au plus) complétée par un joker.
     *
     * @param list<string> $hand
     *
     * @return list<list<string>>
     */
    private static function withJokers(array $hand): array
    {
        $jokers = array_values(array_filter($hand, static fn (string $code): bool => str_starts_with($code, 'joker-')));
        $cards = array_values(array_filter($hand, static fn (string $code): bool => !str_starts_with($code, 'joker-')));
        $groups = [];
        $used = [];

        foreach ($cards as $i => $a) {
            foreach (\array_slice($cards, $i + 1) as $b) {
                if ([] === $jokers || \in_array($a, $used, true) || \in_array($b, $used, true)) {
                    continue;
                }

                $group = [$a, $b, $jokers[0]];

                try {
                    RamiMeld::build($group);
                } catch (RamiException) {
                    continue;
                }

                $groups[] = $group;
                $used = [...$used, $a, $b];
                array_shift($jokers);
            }
        }

        return $groups;
    }

    /**
     * Combien de cartes de la main vont avec celle-ci (même hauteur, ou même couleur à deux rangs au plus).
     *
     * @param list<string> $hand
     */
    private static function neighbours(array $hand, string $code): int
    {
        $card = RamiCard::fromCode($code);
        $count = 0;

        foreach ($hand as $other) {
            $o = RamiCard::fromCode($other);

            if ($other !== $code && !$o->isJoker() && ($o->rank === $card->rank || ($o->suit === $card->suit && abs($o->rank - $card->rank) <= 2))) {
                ++$count;
            }
        }

        return $count;
    }
}
