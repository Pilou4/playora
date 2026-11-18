<?php

declare(strict_types=1);

namespace App\Game\Belote;

/**
 * Résultat d'une donne, gardé pour l'historique et les statistiques.
 */
final readonly class DealResult
{
    /**
     * @param array{int, int} $cardPoints points ramassés dans les plis (dix de der compris)
     * @param array{int, int} $scored     points ajoutés au score de chaque équipe
     */
    public function __construct(
        public int $number,
        public int $dealer,
        public int $taker,
        /** Null en tout atout et en sans atout (voir $mode). */
        public ?Suit $trump,
        public Outcome $outcome,
        public array $cardPoints,
        public array $scored,
        public ?int $beloteTeam,
        public ?int $capotTeam,
        public int $pendingAfter,
        /** Coinche et contrée : contrat et multiplicateur (1, coinché 2, surcoinché 4). */
        public ?int $contract = null,
        public int $multiplier = 1,
        /** Points d'annonces de chaque équipe (une seule en marque). @var array{int, int} */
        public array $announces = [0, 0],
        public TrumpMode $mode = TrumpMode::Suit,
    ) {
    }

    public function takerTeam(): int
    {
        return BeloteRules::team($this->taker);
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
            'trump' => $this->trump?->value,
            'mode' => $this->mode->value,
            'outcome' => $this->outcome->value,
            'cardPoints' => $this->cardPoints,
            'scored' => $this->scored,
            'beloteTeam' => $this->beloteTeam,
            'capotTeam' => $this->capotTeam,
            'contract' => $this->contract,
            'multiplier' => $this->multiplier,
            'announces' => $this->announces,
        ];
    }
}
