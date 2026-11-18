<?php

declare(strict_types=1);

namespace App\Dto;

use App\Game\Tarot\Poignee;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tarot : la poignée annoncée (simple, double ou triple).
 */
final readonly class TarotPoigneeRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Choisissez une poignée.')]
        #[Assert\Choice(choices: ['simple', 'double', 'triple'], message: 'Poignée inconnue.')]
        public string $poignee = '',
    ) {
    }

    public function poignee(): Poignee
    {
        return Poignee::from($this->poignee);
    }
}
