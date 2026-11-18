<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Solitaire : la carte déplacée (« coeur-as », « pique-10 »…) et sa destination (« famille » ou « colonne-0 » à « colonne-6 »).
 */
final readonly class SolitaireMoveRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Choisissez une carte.')]
        #[Assert\Regex(pattern: '/^(pique|coeur|carreau|trefle)-(as|[2-9]|10|valet|dame|roi)$/', message: 'Carte inconnue.')]
        public string $card = '',
        #[Assert\NotBlank(message: 'Choisissez où poser la carte.')]
        #[Assert\Regex(pattern: '/^(famille|colonne-[0-6])$/', message: 'Destination inconnue.')]
        public string $to = '',
        /** Rangement automatique : les cartes qui ne servent plus montent sur leur famille. */
        public bool $auto = true,
    ) {
    }
}
