<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Président : les cartes posées ou données (« coeur-7 », « pique-as »…).
 */
final readonly class PresidentCardsRequest
{
    /**
     * @param list<string> $cards
     */
    public function __construct(
        #[Assert\Count(min: 1, max: 4, minMessage: 'Choisissez au moins une carte.', maxMessage: 'On pose 4 cartes au plus.')]
        #[Assert\All([
            new Assert\Type(type: 'string', message: 'Carte inconnue.'),
            new Assert\Regex(pattern: '/^(pique|coeur|carreau|trefle)-([2-9]|10|valet|dame|roi|as)$/', message: 'Carte inconnue.'),
        ])]
        public array $cards = [],
    ) {
    }
}
