<?php

declare(strict_types=1);

namespace App\Game\Belote;

/**
 * Les annonces (règles GameDuell) : on les cherche dans une main, puis on désigne l'équipe qui les marque.
 *
 * Suites, dans l'ordre As, Roi, Dame, Valet, 10, 9, 8, 7 : 3 cartes (tierce) 20, 4 (cinquante) 50,
 * 5 ou plus (cent) 100. Carrés : Valets 200, 9 150, As, 10, Rois ou Dames 100 ; 8 et 7 rien.
 * Une carte ne sert qu'à une seule annonce : on garde la combinaison qui rapporte le plus.
 *
 * Seule l'équipe qui a la meilleure annonce marque (toutes ses annonces) : la plus forte en points,
 * à égalité celle à l'atout ; sinon, en belote, celle du joueur le plus proche du donneur,
 * en coinche, les annonces s'annulent.
 */
final class Announcements
{
    private const array SEQUENCE = ['7' => 0, '8' => 1, '9' => 2, '10' => 3, 'valet' => 4, 'dame' => 5, 'roi' => 6, 'as' => 7];

    /**
     * Les annonces d'une main (8 cartes), sans carte en double, celles qui rapportent le plus.
     *
     * @param list<Card> $hand
     *
     * @return list<Announcement>
     */
    public static function inHand(array $hand): array
    {
        $candidates = [...self::squares($hand), ...self::suites($hand)];
        $best = [];
        $bestPoints = 0;
        $count = \count($candidates);

        // Au plus 2 carrés et 2 suites dans 8 cartes : on essaie toutes les combinaisons
        for ($mask = 1; $mask < (1 << $count); ++$mask) {
            $chosen = [];
            $used = [];

            foreach ($candidates as $index => $candidate) {
                if (0 === ($mask & (1 << $index))) {
                    continue;
                }

                foreach ($candidate->cards as $card) {
                    if (isset($used[$card->code()])) {
                        continue 3;
                    }

                    $used[$card->code()] = true;
                }

                $chosen[] = $candidate;
            }

            $points = array_sum(array_map(static fn (Announcement $a): int => $a->points, $chosen));

            if ($points > $bestPoints) {
                [$best, $bestPoints] = [$chosen, $points];
            }
        }

        usort($best, static fn (Announcement $a, Announcement $b): int => $b->points <=> $a->points);

        return $best;
    }

    /**
     * L'équipe qui marque ses annonces, ou null (aucune annonce, ou annonces annulées).
     *
     * @param array<int, list<Announcement>> $declarations annonces faites, par place
     */
    public static function winningTeam(array $declarations, Suit $trump, int $dealer, bool $cancelOnTie): ?int
    {
        $best = null;
        $tied = false;

        // Dans l'ordre du jeu, à partir de la gauche du donneur : le premier garde l'égalité en belote
        foreach ([1, 2, 3, 4] as $offset) {
            $position = ($dealer + $offset) % 4;

            foreach ($declarations[$position] ?? [] as $announcement) {
                $rank = [$announcement->points, $announcement->isTrump($trump) ? 1 : 0];

                if (null === $best || $rank > $best['rank']) {
                    $best = ['rank' => $rank, 'team' => BeloteRules::team($position)];
                    $tied = false;
                } elseif ($rank === $best['rank'] && BeloteRules::team($position) !== $best['team']) {
                    $tied = true;
                }
            }
        }

        if (null === $best || ($tied && $cancelOnTie)) {
            return null;
        }

        return $best['team'];
    }

    /**
     * Points des annonces d'une équipe.
     *
     * @param array<int, list<Announcement>> $declarations
     */
    public static function teamPoints(array $declarations, int $team): int
    {
        $points = 0;

        foreach ($declarations as $position => $announcements) {
            if (BeloteRules::team($position) === $team) {
                $points += array_sum(array_map(static fn (Announcement $a): int => $a->points, $announcements));
            }
        }

        return $points;
    }

    /**
     * @param list<Card> $hand
     *
     * @return list<Announcement>
     */
    private static function squares(array $hand): array
    {
        $squares = [];

        foreach ([Rank::Jack->value => 200, Rank::Nine->value => 150, Rank::Ace->value => 100, Rank::Ten->value => 100, Rank::King->value => 100, Rank::Queen->value => 100] as $rank => $points) {
            $cards = array_values(array_filter($hand, static fn (Card $card): bool => $card->rank->value === (string) $rank));

            if (4 === \count($cards)) {
                $squares[] = new Announcement($points, $cards, null);
            }
        }

        return $squares;
    }

    /**
     * Les plus longues suites de chaque couleur (3 cartes au moins).
     *
     * @param list<Card> $hand
     *
     * @return list<Announcement>
     */
    private static function suites(array $hand): array
    {
        $suites = [];

        foreach (Suit::cases() as $suit) {
            $cards = array_values(array_filter($hand, static fn (Card $card): bool => $card->suit === $suit));
            usort($cards, static fn (Card $a, Card $b): int => self::SEQUENCE[$a->rank->value] <=> self::SEQUENCE[$b->rank->value]);
            $run = [];

            foreach ([...$cards, null] as $card) {
                if (null !== $card && ([] === $run || self::SEQUENCE[$card->rank->value] === self::SEQUENCE[$run[\count($run) - 1]->rank->value] + 1)) {
                    $run[] = $card;

                    continue;
                }

                if (\count($run) >= 3) {
                    $suites[] = new Announcement(match (\count($run)) { 3 => 20, 4 => 50, default => 100 }, $run, $suit);
                }

                $run = null === $card ? [] : [$card];
            }
        }

        return $suites;
    }
}
