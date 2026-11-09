<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameRoom;
use App\Entity\User;
use App\Enum\Game;
use App\Enum\RoomStatus;
use App\Enum\RoomVisibility;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameRoom>
 */
final class GameRoomRepository extends ServiceEntityRepository
{
    public const int LIST_LIMIT = 100;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameRoom::class);
    }

    /**
     * Salons publics en attente, avec leurs places et leurs joueurs (sans requête en plus par salon).
     *
     * @return list<GameRoom>
     */
    public function findWaitingPublic(Game $game): array
    {
        $ids = array_column($this->createQueryBuilder('r')
            ->select('r.id')
            ->where('r.game = :game')
            ->andWhere('r.status = :status')
            ->andWhere('r.visibility = :visibility')
            ->setParameter('game', $game->value)
            ->setParameter('status', RoomStatus::Waiting->value)
            ->setParameter('visibility', RoomVisibility::Public->value)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(self::LIST_LIMIT)
            ->getQuery()
            ->getScalarResult(), 'id');

        if ([] === $ids) {
            return [];
        }

        return $this->withSeats()
            ->where('r.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Salon en attente ou en cours où le joueur est assis (un seul à la fois).
     */
    public function findCurrentFor(User $user): ?GameRoom
    {
        $row = $this->createQueryBuilder('r')
            ->select('r.id')
            ->innerJoin('r.seats', 's')
            ->where('s.user = :user')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', [RoomStatus::Waiting->value, RoomStatus::Playing->value])
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null === $row ? null : $this->findWithSeats((int) $row['id']);
    }

    public function findWithSeats(int $id): ?GameRoom
    {
        return $this->withSeats()->where('r.id = :id')->setParameter('id', $id)->getQuery()->getOneOrNullResult();
    }

    /**
     * Numéros des salons en attente où au moins un joueur n'a plus donné signe de vie depuis $since.
     *
     * @return list<int>
     */
    public function findIdsWithAbsentPlayers(Game $game, \DateTimeImmutable $since): array
    {
        return array_map('intval', array_column($this->createQueryBuilder('r')
            ->select('DISTINCT r.id')
            ->innerJoin('r.seats', 's')
            ->where('r.game = :game')
            ->andWhere('r.status = :status')
            ->andWhere('s.user IS NOT NULL')
            ->andWhere('s.lastSeenAt < :since')
            ->setParameter('game', $game->value)
            ->setParameter('status', RoomStatus::Waiting->value)
            ->setParameter('since', $since)
            ->getQuery()
            ->getScalarResult(), 'id'));
    }

    /**
     * Verrouille la ligne du salon jusqu'à la fin de la transaction : deux joueurs
     * ne peuvent pas prendre la dernière place au même moment.
     */
    public function lock(GameRoom $room): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->lock($room, LockMode::PESSIMISTIC_WRITE);
        $entityManager->refresh($room);

        foreach ($room->getSeats() as $seat) {
            $entityManager->refresh($seat);
        }
    }

    public function inviteCodeExists(string $code): bool
    {
        return null !== $this->findOneBy(['inviteCode' => $code]);
    }

    private function withSeats(): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->addSelect('s', 'u')
            ->innerJoin('r.seats', 's')
            ->leftJoin('s.user', 'u');
    }
}
