<?php

declare(strict_types=1);

namespace App\Game\Belote;

/**
 * Compte d'une donne de belote classique (règles GameDuell) : 162 points (dix de der compris),
 * belote-rebelote 20, capot 252 (le dernier pli vaut alors 100).
 *
 * Le preneur doit faire plus que la défense, belote comprise : avec une belote, 92 points sur 182.
 * Litige (égalité) : l'équipe qui a pris perd ses points, la défense marque les siens.
 *
 * Annonces (belote avec annonces) : elles comptent pour savoir si le preneur a fait plus que la défense
 * et se marquent avec les points de l'équipe ; si le preneur chute, la défense marque aussi les siennes.
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
     * @param array{int, int} $announces points d'annonces de chaque équipe (une seule en marque)
     */
    public static function score(
        int $number,
        int $dealer,
        int $taker,
        Suit $trump,
        array $cardPoints,
        array $tricksWon,
        ?int $beloteTeam,
        array $announces = [0, 0],
    ): DealResult {
        $takerTeam = BeloteRules::team($taker);
        $defense = 1 - $takerTeam;
        $belote = [0, 0];

        if (null !== $beloteTeam) {
            $belote[$beloteTeam] = self::BELOTE;
        }

        $capotTeam = array_search(8, $tricksWon, true);
        $capotTeam = false === $capotTeam ? null : $capotTeam;

        $takerTotal = $cardPoints[$takerTeam] + $belote[$takerTeam] + $announces[$takerTeam];
        $defenseTotal = $cardPoints[$defense] + $belote[$defense] + $announces[$defense];

        $outcome = match (true) {
            null !== $capotTeam => $capotTeam === $takerTeam ? Outcome::Made : Outcome::Failed,
            $takerTotal > $defenseTotal => Outcome::Made,
            $takerTotal < $defenseTotal => Outcome::Failed,
            default => Outcome::Litige,
        };

        $scored = $belote;

        if (Outcome::Made === $outcome) {
            $scored[0] += $announces[0];
            $scored[1] += $announces[1];
        } elseif (Outcome::Failed === $outcome) {
            $scored[$defense] += $announces[0] + $announces[1];
        } else {
            $scored[$defense] += $announces[$defense];
        }

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

        return new DealResult($number, $dealer, $taker, $trump, $outcome, $cardPoints, $scored, $beloteTeam, $capotTeam, 0, announces: $announces);
    }
}
