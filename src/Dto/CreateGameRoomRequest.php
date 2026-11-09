<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données envoyées pour créer un salon. Le reste (joueurs, manches, temps) est fixé par le jeu.
 */
final readonly class CreateGameRoomRequest
{
    public function __construct(
        #[Assert\Choice(choices: [501, 1001, 1501], message: 'Nombre de points non autorisé.')]
        public int $targetPoints = 1001,
        public bool $announcements = false,
    ) {
    }
}
