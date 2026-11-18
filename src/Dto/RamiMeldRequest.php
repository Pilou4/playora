<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Rami : les combinaisons posées d'un coup, chacune une liste de cartes (« coeur-7-1 », « joker-2 »…).
 */
final readonly class RamiMeldRequest
{
    public function __construct(
        /** @var list<list<string>> */
        #[Assert\Count(min: 1, max: 6, minMessage: 'Choisissez les cartes à poser.', maxMessage: 'Trop de combinaisons d’un coup.')]
        #[Assert\All([
            new Assert\Type('array', message: 'Combinaison inconnue.'),
            new Assert\Count(min: 3, max: 13, minMessage: 'Une combinaison compte au moins 3 cartes.', maxMessage: 'Combinaison trop longue.'),
            new Assert\All([
                new Assert\Type('string', message: 'Carte inconnue.'),
                new Assert\Regex(pattern: RamiCardRequest::CARD, message: 'Carte inconnue.'),
            ]),
        ])]
        public array $groups = [],
    ) {
    }
}
