<?php

declare(strict_types=1);

namespace App\Game\Belote;

/**
 * Compte d'une donne de coinche ou de contrée (règles GameDuell, identiques pour les deux jeux).
 *
 * Contrat rempli : l'équipe du preneur fait au moins le contrat et au moins 82 points (belote comprise).
 * Capot (250) : l'équipe gagne les 8 plis ; générale (500) : le preneur gagne seul les 8 plis.
 * Capot ou générale : le dernier pli vaut 100 points (252 au total).
 *
 * - Rempli : chaque équipe marque ses points, le preneur y ajoute le contrat.
 * - Chuté : la défense marque 162 + le contrat, le preneur rien.
 * - Coinché (×2) ou surcoinché (×4) : rempli, le preneur marque ses points + le contrat multiplié
 *   et la défense rien ; chuté, la défense marque 162 + le contrat multiplié.
 * - Litige (autant de points de chaque côté) : chaque équipe marque ses points.
 * - La belote-rebelote (20) compte toujours et reste à l'équipe qui l'a, même si elle chute ;
 *   en tout atout, chaque Roi et Dame de la même couleur dans une main vaut 20 ; en sans atout, pas de belote.
 * - Annonces (coinche) : elles comptent pour le contrat et se marquent avec les points de l'équipe ;
 *   si le preneur chute, la défense marque aussi les siennes.
 */
final class CoincheScorer
{
    public const int CAPOT_CONTRACT = 250;
    public const int GENERALE_CONTRACT = 500;
    public const int MIN_CONTRACT = 80;
    public const int MAX_CONTRACT = 160;
    public const int BASE = 162;
    public const int HALF = 82;
    /** Points d'un capot ou d'une générale : 152 dans les plis + 100 pour le dernier. */
    public const int CAPOT_POINTS = 252;

    /**
     * @param array{int, int} $cardPoints   points ramassés (dix de der compris)
     * @param array{int, int} $tricksWon    plis de chaque équipe
     * @param int             $takerTricks plis gagnés par le preneur lui-même
     * @param array{int, int} $announces   points d'annonces de chaque équipe (une seule en marque)
     * @param array{int, int}|null $belotePoints points de belote de chaque équipe (sinon : 20 pour $beloteTeam)
     */
    public static function score(
        int $number,
        int $dealer,
        int $taker,
        ?Suit $trump,
        int $contract,
        int $multiplier,
        array $cardPoints,
        array $tricksWon,
        ?int $beloteTeam,
        int $takerTricks = 0,
        array $announces = [0, 0],
        TrumpMode $mode = TrumpMode::Suit,
        ?array $belotePoints = null,
    ): DealResult {
        $takerTeam = BeloteRules::team($taker);
        $defense = 1 - $takerTeam;
        $belote = [0, 0];

        if (null !== $belotePoints) {
            // Tout atout : 20 points par belote, à chaque équipe les siennes
            $belote = $belotePoints;
        } elseif (null !== $beloteTeam) {
            $belote[$beloteTeam] = BeloteScorer::BELOTE;
        }

        $capotTeam = array_search(8, $tricksWon, true);
        $capotTeam = false === $capotTeam ? null : $capotTeam;

        // Capot ou générale : le dernier pli vaut 100 au lieu de 10
        if (null !== $capotTeam) {
            $cardPoints[$capotTeam] = self::CAPOT_POINTS;
            $cardPoints[1 - $capotTeam] = 0;
        }

        $takerTotal = $cardPoints[$takerTeam] + $belote[$takerTeam] + $announces[$takerTeam];
        $defenseTotal = $cardPoints[$defense] + $belote[$defense] + $announces[$defense];

        $outcome = match (true) {
            self::GENERALE_CONTRACT === $contract => 8 === $takerTricks ? Outcome::Made : Outcome::Failed,
            self::CAPOT_CONTRACT === $contract => $capotTeam === $takerTeam ? Outcome::Made : Outcome::Failed,
            $takerTotal < $contract => Outcome::Failed,
            $takerTotal === $defenseTotal => Outcome::Litige,
            $takerTotal >= self::HALF => Outcome::Made,
            default => Outcome::Failed,
        };

        $scored = $belote;

        if (Outcome::Litige === $outcome) {
            $scored[0] += $cardPoints[0] + $announces[0];
            $scored[1] += $cardPoints[1] + $announces[1];
        } elseif (Outcome::Made === $outcome) {
            $scored[$takerTeam] += $cardPoints[$takerTeam] + $announces[$takerTeam] + $contract * $multiplier;
            $scored[$defense] += 1 === $multiplier ? $cardPoints[$defense] + $announces[$defense] : 0;
        } else {
            $scored[$defense] += self::BASE + $contract * $multiplier + $announces[0] + $announces[1];
        }

        return new DealResult(
            $number,
            $dealer,
            $taker,
            $trump,
            $outcome,
            $cardPoints,
            $scored,
            $beloteTeam,
            $capotTeam,
            0,
            $contract,
            $multiplier,
            $announces,
            $mode,
        );
    }

    /**
     * @return list<int> contrats possibles, du plus petit au plus grand (capot et générale compris)
     */
    public static function contracts(): array
    {
        return [...range(self::MIN_CONTRACT, self::MAX_CONTRACT, 10), self::CAPOT_CONTRACT, self::GENERALE_CONTRACT];
    }
}
