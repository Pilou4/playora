<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Rami : une carte de la main (« coeur-7-1 », « joker-2 »), et la combinaison visée quand on complète
 * une combinaison posée ou qu'on y reprend un joker (son numéro dans la liste des combinaisons).
 */
final readonly class RamiCardRequest
{
    public const string CARD = '/^((pique|coeur|carreau|trefle)-(as|[2-9]|10|valet|dame|roi)-[12]|joker-[1-4])$/';

    public function __construct(
        #[Assert\NotBlank(message: 'Choisissez une carte.')]
        #[Assert\Regex(pattern: self::CARD, message: 'Carte inconnue.')]
        public string $card = '',
        #[Assert\PositiveOrZero(message: 'Combinaison inconnue.')]
        public int $meld = 0,
    ) {
    }
}
