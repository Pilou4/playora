<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Spider : la pile talon choisie (0 à 4), dont les 10 cartes vont une sur chaque pile.
 */
final readonly class SpiderDealRequest
{
    public function __construct(
        #[Assert\Range(notInRangeMessage: 'Pile talon inconnue.', min: 0, max: 4)]
        public int $pile = 0,
    ) {
    }
}
