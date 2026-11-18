<?php

declare(strict_types=1);

namespace App\Dto;

use App\Game\Tarot\TarotCard;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tarot : les cartes de l'écart, 6 (ou 3 à 5 joueurs) ; le moteur vérifie le nombre exact.
 */
final readonly class TarotDiscardRequest
{
    /**
     * @param list<string> $cards
     */
    public function __construct(
        #[Assert\Count(min: 3, max: 6, minMessage: 'Choisissez les cartes à écarter.', maxMessage: 'Choisissez les cartes à écarter.')]
        #[Assert\All([new Assert\Type('string'), new Assert\Regex(pattern: '/^(excuse|(pique|coeur|carreau|trefle)-(\d{1,2}|valet|cavalier|dame|roi)|atout-\d{1,2})$/', message: 'Carte inconnue.')])]
        public array $cards = [],
    ) {
    }

    /**
     * @return list<TarotCard>
     */
    public function cards(): array
    {
        return array_map(TarotCard::fromCode(...), array_values($this->cards));
    }
}
