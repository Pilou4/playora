<?php

declare(strict_types=1);

namespace App\Dto;

use App\Game\Belote\CoincheScorer;
use App\Game\Belote\Suit;
use App\Game\Belote\TrumpMode;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Coinche et contrée : annonce d'un contrat (80 à 160 par dizaines, capot 250, générale 500) à une couleur,
 * ou (coinche) en tout atout ou en sans atout.
 */
final readonly class AnnounceRequest
{
    public function __construct(
        #[Assert\Choice(callback: [CoincheScorer::class, 'contracts'], message: 'Annonce impossible : de 80 à 160 par dizaines, ou capot.')]
        public int $contract = CoincheScorer::MIN_CONTRACT,
        #[Assert\NotNull(message: 'Choisissez une couleur.')]
        #[Assert\Choice(choices: ['pique', 'coeur', 'carreau', 'trefle', 'tout-atout', 'sans-atout'], message: 'Couleur inconnue.')]
        public ?string $suit = null,
    ) {
    }

    /** La couleur d'atout ; null en tout atout et en sans atout. */
    public function suit(): ?Suit
    {
        return Suit::tryFrom((string) $this->suit);
    }

    public function mode(): TrumpMode
    {
        return TrumpMode::tryFrom((string) $this->suit) ?? TrumpMode::Suit;
    }
}
