<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Spider : la carte déplacée (« pique-roi-1 », avec son numéro d'exemplaire) et sa destination
 * (« colonne-0 » à « colonne-9 », ou une pile du Joker « joker-0 », « joker-1 »).
 */
final readonly class SpiderMoveRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Choisissez une carte.')]
        #[Assert\Regex(pattern: '/^(pique|coeur|carreau|trefle)-(as|[2-9]|10|valet|dame|roi)-[1-8]$/', message: 'Carte inconnue.')]
        public string $card = '',
        #[Assert\NotBlank(message: 'Choisissez où poser la carte.')]
        #[Assert\Regex(pattern: '/^(colonne-\d|joker-[01])$/', message: 'Destination inconnue.')]
        public string $to = '',
    ) {
    }
}
