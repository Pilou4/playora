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
     * Joueurs d'une partie, par place (à partir de 1).
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

    /**
     * Joueurs de plusieurs parties en une requête (pseudos compris), pour les listes de l'admin.
     *
     * @param list<int> $rooms
     *
     * @return array<int, array<int, GamePlayer>> salon => place => joueur
     */
    public function findByRoomIds(array $rooms): array
    {
        if ([] === $rooms) {
            return [];
        }

        /** @var list<array{0: GamePlayer, room: int|string}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->addSelect('u', 'IDENTITY(p.room) AS room')
            ->leftJoin('p.user', 'u')
            ->where('p.room IN (:rooms)')
            ->setParameter('rooms', $rooms)
            ->orderBy('p.position', 'ASC')
            ->getQuery()
            ->getResult();

        $players = [];

        foreach ($rows as $row) {
            $players[(int) $row['room']][$row[0]->getPosition()] = $row[0];
        }

        return $players;
    }
}
