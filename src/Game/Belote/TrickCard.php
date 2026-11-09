<?php

declare(strict_types=1);

namespace App\Game\Belote;

/**
 * Une carte posée dans le pli, avec la place (0 à 3) de celui qui l'a jouée.
 */
final readonly class TrickCard
{
    public function __construct(
        public int $position,
        public Card $card,
    ) {
    }
}
