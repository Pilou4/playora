<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Solitaire : clic sur le talon (avec ou sans le rangement automatique).
 */
final readonly class SolitaireDrawRequest
{
    public function __construct(
        public bool $auto = true,
    ) {
    }
}
