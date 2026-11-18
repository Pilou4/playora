<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameRoom;
use App\Entity\TarotGame;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TarotGame>
 */
final class TarotGameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TarotGame::class);
    }

    public function findOneByRoom(GameRoom $room): ?TarotGame
    {
        return $this->findOneBy(['room' => $room]);
    }

    /**
     * Verrouille la partie jusqu'à la fin de la transaction : deux coups ne s'appliquent jamais en même temps.
     */
    public function lock(TarotGame $game): void
    {
        $this->getEntityManager()->lock($game, LockMode::PESSIMISTIC_WRITE);
        $this->getEntityManager()->refresh($game);
    }
}
