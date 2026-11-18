<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameRoom;
use App\Entity\PatienceBoard;
use App\Entity\PatienceGame;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PatienceBoard>
 */
final class PatienceBoardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PatienceBoard::class);
    }

    public function findOne(PatienceGame $game, int $position, int $round): ?PatienceBoard
    {
        return $this->findOneBy(['game' => $game, 'position' => $position, 'round' => $round]);
    }

    /**
     * Les grilles d'une manche, par place.
     *
     * @return array<int, PatienceBoard>
     */
    public function forRound(PatienceGame $game, int $round): array
    {
        $boards = [];

        foreach ($this->findBy(['game' => $game, 'round' => $round]) as $board) {
            $boards[$board->getPosition()] = $board;
        }

        ksort($boards);

        return $boards;
    }

    /**
     * Scores de chaque grille : manche => place => score (null tant que la grille n'est pas terminée). Une seule requête.
     *
     * @return array<int, array<int, ?int>>
     */
    public function scores(PatienceGame $game): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select('b.round, b.position, b.score, b.finishedAt')
            ->where('b.game = :game')
            ->setParameter('game', $game)
            ->orderBy('b.round')
            ->addOrderBy('b.position')
            ->getQuery()
            ->getArrayResult();

        $scores = [];

        foreach ($rows as $row) {
            $scores[$row['round']][$row['position']] = null === $row['finishedAt'] ? null : $row['score'];
        }

        return $scores;
    }

    /**
     * Duel solo : le joueur assis à cette place (à partir de 1) a-t-il terminé sa grille ?
     */
    public function isFinishedAt(GameRoom $room, int $seatPosition): bool
    {
        return null !== $this->createQueryBuilder('b')
            ->select('b.id')
            ->innerJoin('b.game', 'g')
            ->where('g.room = :room')
            ->andWhere('b.position = :position')
            ->andWhere('b.finishedAt IS NOT NULL')
            ->setParameter('room', $room)
            ->setParameter('position', $seatPosition - 1)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
