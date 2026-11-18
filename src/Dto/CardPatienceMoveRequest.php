<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * FreeCell, Pyramide, Golf solitaire : la carte jouée (« coeur-7 ») et sa place : « colonne-0 » à « colonne-7 »,
 * « cellule-0 » à « cellule-3 », « famille », « pile », « seul » (un Roi à la Pyramide) ou l'autre carte d'une paire.
 */
final readonly class CardPatienceMoveRequest
{
    private const string CARD = '(pique|coeur|carreau|trefle)-(as|[2-9]|10|valet|dame|roi)';

    public function __construct(
        #[Assert\NotBlank(message: 'Choisissez une carte.')]
        #[Assert\Regex(pattern: '/^'.self::CARD.'$/', message: 'Carte inconnue.')]
        public string $card = '',
        #[Assert\NotBlank(message: 'Choisissez où jouer la carte.')]
        #[Assert\Regex(pattern: '/^(colonne-[0-7]|cellule-[0-3]|famille|pile|seul|'.self::CARD.')$/', message: 'Place inconnue.')]
        public string $to = '',
    ) {
    }
}
