<?php

declare(strict_types=1);

namespace App\Dto;

use App\Game\Tarot\TarotCard;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tarot : la carte jouée (code : « coeur-roi », « atout-21 », « excuse »…).
 */
final readonly class TarotPlayCardRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Choisissez une carte.')]
        #[Assert\Regex(pattern: '/^(excuse|(pique|coeur|carreau|trefle)-(\d{1,2}|valet|cavalier|dame|roi)|atout-\d{1,2})$/', message: 'Carte inconnue.')]
        public string $card = '',
    ) {
    }

    public function card(): TarotCard
    {
        return TarotCard::fromCode($this->card);
    }
}
