<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameRoom;
use App\Entity\RamiGame;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RamiGame>
 */
final class RamiGameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RamiGame::class);
    }

    public function findOneByRoom(GameRoom $room): ?RamiGame
    {
        return $this->findOneBy(['room' => $room]);
    }

    /**
     * Verrouille la partie jusqu'à la fin de la transaction : deux coups ne s'appliquent jamais en même temps.
     */
    public function lock(RamiGame $game): void
    {
        $this->getEntityManager()->lock($game, LockMode::PESSIMISTIC_WRITE);
        $this->getEntityManager()->refresh($game);
    }
}
