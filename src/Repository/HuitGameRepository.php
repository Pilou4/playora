<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameRoom;
use App\Entity\HuitGame;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HuitGame>
 */
final class HuitGameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HuitGame::class);
    }

    public function findOneByRoom(GameRoom $room): ?HuitGame
    {
        return $this->findOneBy(['room' => $room]);
    }

    /**
     * Verrouille la partie jusqu'à la fin de la transaction : deux coups ne s'appliquent jamais en même temps.
     */
    public function lock(HuitGame $game): void
    {
        $this->getEntityManager()->lock($game, LockMode::PESSIMISTIC_WRITE);
        $this->getEntityManager()->refresh($game);
    }
}
