<?php

declare(strict_types=1);

namespace App\Dto;

use App\Game\Tarot\TarotContract;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tarot : passer (contrat absent ou null) ou annoncer petite, garde, garde sans ou garde contre.
 */
final readonly class TarotBidRequest
{
    public function __construct(
        #[Assert\Choice(choices: ['petite', 'garde', 'garde-sans', 'garde-contre'], message: 'Enchère inconnue.')]
        public ?string $contract = null,
    ) {
    }

    public function contract(): ?TarotContract
    {
        return null === $this->contract ? null : TarotContract::from($this->contract);
    }
}
