<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Game;
use App\Repository\GameSettingRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Jeux actifs ou inactifs (réglé par l'admin). Un jeu inactif est caché de la liste des jeux
 * et fermé aux joueurs ; les admins le voient et peuvent y jouer pour le tester.
 * Une seule requête par page : la liste des jeux inactifs est gardée jusqu'à la fin de la requête.
 */
final class GameAvailability implements ResetInterface
{
    /** @var list<Game>|null */
    private ?array $inactive = null;

    public function __construct(
        private readonly GameSettingRepository $settings,
        private readonly Security $security,
    ) {
    }

    public function isActive(Game $game): bool
    {
        return !\in_array($game, $this->inactive(), true);
    }

    /**
     * Le joueur connecté peut-il voir ce jeu et y jouer ? Oui s'il est actif, ou si c'est un admin.
     */
    public function isOpen(Game $game): bool
    {
        return $this->isActive($game) || $this->security->isGranted('ROLE_ADMIN');
    }

    public function reset(): void
    {
        $this->inactive = null;
    }

    /**
     * @return list<Game>
     */
    private function inactive(): array
    {
        return $this->inactive ??= $this->settings->inactiveGames();
    }
}
