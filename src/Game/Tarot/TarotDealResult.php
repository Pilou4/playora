<?php

declare(strict_types=1);

namespace App\Game\Tarot;

/**
 * Résultat d'une donne : le contrat, ce qu'a fait le preneur et les points de chacun.
 */
final readonly class TarotDealResult
{
    /**
     * @param list<int>          $scored   points marqués par chaque place (somme nulle)
     * @param array<int, string> $poignees poignées annoncées, par place
     */
    public function __construct(
        public int $number,
        public int $dealer,
        public int $taker,
        public TarotContract $contract,
        public bool $made,
        /** Points du preneur, en demi-points (ses plis, plus le chien ou l'écart selon le contrat). */
        public int $takerHalfPoints,
        public int $bouts,
        public int $required,
        /** Valeur de la donne, avant les coefficients de chaque place (voir TarotScorer). */
        public int $value,
        public array $scored,
        /** Camp qui a mené le Petit au bout : 0 le preneur, 1 la défense, null personne. */
        public ?int $petitAuBout,
        public array $poignees,
        public bool $chelemAnnounced,
        /** Camp qui a fait tous les plis (0 le preneur, 1 la défense), null sinon. */
        public ?int $chelem,
        /** À 5 joueurs : le partenaire du preneur (null s'il a joué seul, et à 3 ou 4 joueurs). */
        public ?int $partner = null,
    ) {
    }

    /** Points du preneur, arrondis comme sur la feuille de marque (0,5 compte pour 1 en sa faveur ou en sa défaveur). */
    public function takerPoints(): float
    {
        return $this->takerHalfPoints / 2;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'dealer' => $this->dealer,
            'taker' => $this->taker,
            'contract' => $this->contract->value,
            'contractLabel' => $this->contract->label(),
            'made' => $this->made,
            'takerPoints' => $this->takerPoints(),
            'bouts' => $this->bouts,
            'required' => $this->required,
            'value' => $this->value,
            'scored' => $this->scored,
            'petitAuBout' => $this->petitAuBout,
            'poignees' => $this->poignees,
            'chelemAnnounced' => $this->chelemAnnounced,
            'chelem' => $this->chelem,
            'partner' => $this->partner,
        ];
    }
}
