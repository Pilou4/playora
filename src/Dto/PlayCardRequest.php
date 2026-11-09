<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PlayCardRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Choisissez une carte.')]
        #[Assert\Regex(pattern: '/^(pique|coeur|carreau|trefle)-(7|8|9|10|valet|dame|roi|as)$/', message: 'Carte inconnue.')]
        public string $card = '',
    ) {
    }
}
