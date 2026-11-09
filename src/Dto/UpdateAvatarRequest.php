<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Avatar choisi (code des pièces) ; null pour revenir à l'initiale du pseudo.
 */
final readonly class UpdateAvatarRequest
{
    public function __construct(
        #[Assert\Length(max: 255)]
        public ?string $code = null,
    ) {
    }
}
