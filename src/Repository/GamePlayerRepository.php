<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GamePlayer;
use App\Entity\GameRoom;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GamePlayer>
 */
final class GamePlayerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GamePlayer::class);
    }

    /**
     * Joueurs d'une partie, par place (1 à 4).
     *
     * @return array<int, GamePlayer>
     */
    public function findByRoomIndexed(GameRoom $room): array
    {
        $players = [];

        foreach ($this->findBy(['room' => $room]) as $player) {
            $players[$player->getPosition()] = $player;
        }

        return $players;
    }
}
