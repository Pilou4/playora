<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GameRoom;
use App\Entity\PatienceBoard;
use App\Entity\User;
use App\Enum\RoomStatus;
use App\Repository\GameRoomRepository;
use App\Repository\PatienceBoardRepository;

/**
 * Le salon où le joueur est occupé (un seul à la fois). Jeux solo (solitaire, Spider) : une fois sa partie terminée,
 * le joueur est libre, même si le salon reste ouvert à d'autres joueurs.
 */
final readonly class PlayerRoom
{
    public function __construct(
        private GameRoomRepository $rooms,
        private PatienceBoardRepository $boards,
    ) {
    }

    /**
     * Le salon ouvert le plus récent où le joueur a encore quelque chose à faire. Les duels solo qu'il a déjà finis
     * (encore ouverts pour les autres joueurs) sont passés : ils ne doivent jamais cacher le duel qu'il vient de rejoindre.
     */
    public function current(User $user): ?GameRoom
    {
        /** @var list<array{id: int}> $ids */
        $ids = $this->rooms->createQueryBuilder('r')
            ->select('r.id')
            ->innerJoin('r.seats', 's')
            ->where('s.user = :user')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', [RoomStatus::Waiting->value, RoomStatus::Playing->value])
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getScalarResult();

        foreach ($ids as ['id' => $id]) {
            $room = $this->rooms->findWithSeats((int) $id);
            $seat = $room?->getSeatOf($user);

            if (null === $room || null === $seat) {
                continue;
            }

            if (!$room->getGame()->isPatience() || !$this->isOver($room, $seat->getPosition())) {
                return $room;
            }
        }

        return null;
    }

    /**
     * Duel solo : la partie du joueur est finie, ou son temps est écoulé (page fermée avant la fin : personne ne l'a
     * encore close). Dans les deux cas, elle ne l'occupe plus et ne doit jamais rouvrir à la place d'une nouvelle.
     */
    private function isOver(GameRoom $room, int $seatPosition): bool
    {
        return null !== $this->boards->createQueryBuilder('b')
            ->select('b.id')
            ->innerJoin('b.game', 'g')
            ->where('g.room = :room')
            ->andWhere('b.position = :position')
            ->andWhere('b.finishedAt IS NOT NULL OR b.startedAt < :expired')
            ->setParameter('room', $room)
            ->setParameter('position', $seatPosition - 1)
            ->setParameter('expired', new \DateTimeImmutable(\sprintf('-%d seconds', PatienceBoard::SECONDS)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
