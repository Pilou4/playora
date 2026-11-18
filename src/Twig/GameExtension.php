<?php

declare(strict_types=1);

namespace App\Twig;

use App\Enum\Game;
use App\Service\GameAvailability;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * game_active('belote') : le jeu est-il actif ? (réglé par l'admin)
 * game_open('belote') : le joueur connecté peut-il le voir et y jouer ? (actif, ou admin)
 */
final class GameExtension extends AbstractExtension
{
    public function __construct(
        private readonly GameAvailability $availability,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('game_active', fn (Game|string $game): bool => $this->availability->isActive(self::game($game))),
            new TwigFunction('game_open', fn (Game|string $game): bool => $this->availability->isOpen(self::game($game))),
        ];
    }

    private static function game(Game|string $game): Game
    {
        return $game instanceof Game ? $game : Game::from($game);
    }
}
