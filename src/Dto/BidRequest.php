<?php

declare(strict_types=1);

namespace App\Dto;

use App\Game\Belote\Suit;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Enchère : prendre (à la couleur choisie au 2e tour) ou passer.
 */
final readonly class BidRequest
{
    public function __construct(
        public bool $take = false,
        #[Assert\Choice(choices: ['pique', 'coeur', 'carreau', 'trefle'], message: 'Couleur inconnue.')]
        public ?string $suit = null,
    ) {
    }

    public function suit(): ?Suit
    {
        return null === $this->suit ? null : Suit::from($this->suit);
    }
}
