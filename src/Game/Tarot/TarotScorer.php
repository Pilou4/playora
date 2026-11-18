<?php

declare(strict_types=1);

namespace App\Game\Tarot;

/**
 * Calcul d'une donne (règles FFT).
 *
 * Le preneur doit faire 56, 51, 41 ou 36 points selon ses bouts (0, 1, 2 ou 3).
 * Valeur = (25 + l'écart, arrondi au point supérieur) × coefficient (petite 1, garde 2, garde sans 4, garde contre 6),
 * positive si le contrat est fait (« juste fait » compris), négative sinon.
 * S'y ajoutent : le Petit au bout (10 × coefficient, au camp qui l'a mené), la poignée (20, 30 ou 40 au camp
 * qui gagne la donne), le chelem (annoncé et fait 400, fait sans annonce 200, annoncé et raté -200 ;
 * fait par la défense : 200 pour elle).
 * Chaque défenseur perd cette valeur une fois ; le preneur la marque 3 fois à 4 joueurs, 2 fois à 3 joueurs ;
 * à 5 joueurs, 2 fois et son partenaire 1 fois (4 fois s'il joue seul). La somme fait toujours 0.
 */
final class TarotScorer
{
    public const int BASE = 25;
    public const int PETIT_AU_BOUT = 10;

    /**
     * @param array<int, Poignee> $poignees poignées annoncées, par place
     * @param array{int, int}     $tricks   plis du preneur (0) et de la défense (1)
     */
    public static function score(
        int $number,
        int $dealer,
        int $taker,
        TarotContract $contract,
        int $takerHalfPoints,
        int $bouts,
        ?int $petitAuBout,
        array $poignees,
        bool $chelemAnnounced,
        array $tricks,
        int $players = 4,
        ?int $partner = null,
    ): TarotDealResult {
        $required = TarotRules::required($bouts);
        $gap = $takerHalfPoints - 2 * $required;
        $made = $gap >= 0;
        $multiplier = $contract->multiplier();
        $sign = $made ? 1 : -1;

        // Écart en points, une demi-point arrondi au point supérieur
        $value = $sign * (self::BASE + intdiv(abs($gap) + 1, 2)) * $multiplier;

        if (null !== $petitAuBout) {
            $value += (0 === $petitAuBout ? 1 : -1) * self::PETIT_AU_BOUT * $multiplier;
        }

        foreach ($poignees as $poignee) {
            $value += $sign * $poignee->bonus();
        }

        $chelem = match (true) {
            0 === $tricks[1] => 0,
            0 === $tricks[0] => 1,
            default => null,
        };
        $value += match (true) {
            0 === $chelem => $chelemAnnounced ? 400 : 200,
            $chelemAnnounced => -200,
            1 === $chelem => -200,
            default => 0,
        };

        $defenders = $players - 1 - (null === $partner ? 0 : 1);
        $scored = array_map(static fn (int $position): int => match ($position) {
            $taker => ($defenders - (null === $partner ? 0 : 1)) * $value,
            $partner => $value,
            default => -$value,
        }, range(0, $players - 1));

        return new TarotDealResult(
            $number,
            $dealer,
            $taker,
            $contract,
            $made,
            $takerHalfPoints,
            $bouts,
            $required,
            $value,
            $scored,
            $petitAuBout,
            array_map(static fn (Poignee $poignee): string => $poignee->value, $poignees),
            $chelemAnnounced,
            $chelem,
            $partner,
        );
    }
}
