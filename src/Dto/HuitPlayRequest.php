<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * 8 américain : la carte posée (« coeur-7-1 »), la couleur choisie avec un 8, et si le joueur a dit « Carte ! ».
 */
final readonly class HuitPlayRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Choisissez une carte.')]
        #[Assert\Regex(pattern: '/^(pique|coeur|carreau|trefle)-(as|[2-9]|10|valet|dame|roi)-[12]$/', message: 'Carte inconnue.')]
        public string $card = '',
        #[Assert\Choice(choices: ['pique', 'coeur', 'carreau', 'trefle'], message: 'Couleur inconnue.')]
        public ?string $suit = null,
        public bool $announce = false,
    ) {
    }
}
