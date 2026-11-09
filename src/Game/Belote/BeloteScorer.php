<?php

declare(strict_types=1);

namespace App\Game\Belote;

/**
 * Compte d'une donne : 162 points (dix de der compris), belote-rebelote 20, capot 252.
 */
final class BeloteScorer
{
    public const int DEAL_POINTS = 162;
    public const int LAST_TRICK = 10;
    public const int BELOTE = 20;
    public const int CAPOT = 252;

    /**
     * @param array{int, int} $cardPoints
     * @param array{int, int} $tricksWon
     */
    public static function score(
        int $number,
        int $dealer,
        int $taker,
        Suit $trump,
        array $cardPoints,
        array $tricksWon,
        ?int $beloteTeam,
        int $pending,
    ): DealResult {
        $takerTeam = BeloteRules::team($taker);
        $defense = 1 - $takerTeam;
        $belote = [0, 0];

        if (null !== $beloteTeam) {
            $belote[$beloteTeam] = self::BELOTE;
        }

        $capotTeam = array_search(8, $tricksWon, true);
        $capotTeam = false === $capotTeam ? null : $capotTeam;

        $takerTotal = $cardPoints[$takerTeam] + $belote[$takerTeam];
        $defenseTotal = $cardPoints[$defense] + $belote[$defense];

        $outcome = match (true) {
            null !== $capotTeam => $capotTeam === $takerTeam ? Outcome::Made : Outcome::Failed,
            $takerTotal > $defenseTotal => Outcome::Made,
            $takerTotal < $defenseTotal => Outcome::Failed,
            default => Outcome::Litige,
        };

        $scored = $belote;

        if (null !== $capotTeam) {
            $scored[$capotTeam] += self::CAPOT;
        } elseif (Outcome::Made === $outcome) {
            $scored[0] += $cardPoints[0];
            $scored[1] += $cardPoints[1];
        } elseif (Outcome::Failed === $outcome) {
            $scored[$defense] += self::DEAL_POINTS;
        } else {
            $scored[$defense] += $cardPoints[$defense];
        }

        // Points mis de côté par un litige : à l'équipe qui gagne cette donne-ci
        $pendingAfter = 0;

        if (Outcome::Litige === $outcome) {
            $pendingAfter = $pending + $cardPoints[$takerTeam];
        } elseif ($pending > 0) {
            $scored[Outcome::Made === $outcome ? $takerTeam : $defense] += $pending;
        }

        return new DealResult($number, $dealer, $taker, $trump, $outcome, $cardPoints, $scored, $beloteTeam, $capotTeam, $pendingAfter);
    }
}
