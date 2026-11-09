<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BeloteGame;
use App\Entity\GameRoom;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BeloteGame>
 */
final class BeloteGameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BeloteGame::class);
    }

    public function findOneByRoom(GameRoom $room): ?BeloteGame
    {
        return $this->findOneBy(['room' => $room]);
    }

    /**
     * Verrouille la partie jusqu'à la fin de la transaction : deux coups ne s'appliquent jamais en même temps.
     */
    public function lock(BeloteGame $game): void
    {
        $this->getEntityManager()->lock($game, LockMode::PESSIMISTIC_WRITE);
        $this->getEntityManager()->refresh($game);
    }
}
