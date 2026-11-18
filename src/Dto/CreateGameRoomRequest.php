<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données envoyées pour créer un salon. Le reste (temps, réglages) est fixé par le jeu.
 * La fin de partie permise dépend du jeu (points à la belote, à la coinche et à la contrée ; donnes au tarot ;
 * manches au solitaire) : elle est vérifiée à la création (GameRoomManager).
 */
final readonly class CreateGameRoomRequest
{
    public function __construct(
        #[Assert\Positive(message: 'Nombre de points non autorisé.')]
        public int $targetPoints = 1001,
        public bool $announcements = false,
        /** Tarot : 3 à 5 joueurs ; patiences : 2 à 5 ; rami : 2 à 6 (la belote, la coinche et la contrée se jouent à 4). Vérifié par jeu à la création. */
        #[Assert\Choice(choices: [2, 3, 4, 5, 6], message: 'Nombre de joueurs non autorisé.')]
        public ?int $players = null,
    ) {
    }
}
