<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Golf : la place d'une carte dans la grille du joueur (0 à 5 : 0 1 2 en haut, 3 4 5 en bas).
 */
final readonly class GolfPlaceRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'Choisissez une carte.')]
        #[Assert\Range(notInRangeMessage: 'Cette place n’existe pas.', min: 0, max: 5)]
        public ?int $index = null,
    ) {
    }
}
