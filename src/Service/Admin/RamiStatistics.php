<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\GamePlayer;
use App\Entity\RamiGame;
use App\Entity\RamiRound;
use App\Enum\Game;
use App\Repository\GamePlayerRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Chiffres du rami pour l'admin. Quelques requêtes groupées par page, jamais une par partie.
 */
final readonly class RamiStatistics
{
    public const int PER_PAGE = 20;

    /** Colonnes que l'on peut trier dans la liste des parties (valeur de ?tri= => expression DQL). */
    public const array SORTS = [
        'numero' => 'g.id',
        'debut' => 'r.startedAt',
        'manches' => 'g.round',
        'etat' => 'g.winnerPosition',
    ];
    public const string DEFAULT_SORT = 'numero';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private GamePlayerRepository $players,
    ) {
    }

    /**
     * @return array{finished: int, playing: int, withBots: int, rounds: int, ramiSec: int}
     */
    public function summary(): array
    {
        $games = $this->entityManager->createQuery(
            'SELECT COUNT(g.id) AS total, SUM(CASE WHEN r.finishedAt IS NULL THEN 0 ELSE 1 END) AS finished FROM '.RamiGame::class.' g JOIN g.room r',
        )->getSingleResult();

        $rounds = $this->entityManager->createQuery(
            'SELECT COUNT(m.id) AS total, SUM(CASE WHEN m.ramiSec = true THEN 1 ELSE 0 END) AS ramiSec FROM '.RamiRound::class.' m',
        )->getSingleResult();

        $withBots = $this->entityManager->createQuery(
            'SELECT COUNT(DISTINCT r.id) FROM '.GamePlayer::class.' p JOIN p.room r WHERE p.bot = true AND r.game = :game',
        )->setParameter('game', Game::Rami)->getSingleScalarResult();

        $finished = (int) $games['finished'];

        return [
            'finished' => $finished,
            'playing' => (int) $games['total'] - $finished,
            'withBots' => (int) $withBots,
            'rounds' => (int) $rounds['total'],
            'ramiSec' => (int) $rounds['ramiSec'],
        ];
    }

    public function pageCount(): int
    {
        $total = (int) $this->entityManager->createQuery('SELECT COUNT(g.id) FROM '.RamiGame::class.' g')->getSingleScalarResult();

        return max(1, (int) ceil($total / self::PER_PAGE));
    }

    /**
     * Une page de parties triée, avec leurs joueurs. Le tri se fait en base (sur toutes les parties).
     *
     * @param string $sort      une clé de SORTS (sinon le tri par défaut)
     * @param string $direction 'asc' ou 'desc'
     *
     * @return list<array{game: RamiGame, players: array<int, GamePlayer>}>
     */
    public function page(int $page, string $sort = self::DEFAULT_SORT, string $direction = 'desc'): array
    {
        $order = self::SORTS[$sort] ?? self::SORTS[self::DEFAULT_SORT];
        $direction = 'asc' === $direction ? 'ASC' : 'DESC';

        /** @var list<RamiGame> $games */
        $games = $this->entityManager->createQuery(
            'SELECT g, r FROM '.RamiGame::class.' g JOIN g.room r ORDER BY '.$order.' '.$direction.', g.id DESC',
        )->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE)->getResult();

        if ([] === $games) {
            return [];
        }

        $players = $this->players->findByRoomIds(array_map(static fn (RamiGame $game): int => (int) $game->getRoom()->getId(), $games));

        return array_map(static fn (RamiGame $game): array => [
            'game' => $game,
            'players' => $players[(int) $game->getRoom()->getId()] ?? [],
        ], $games);
    }

    /**
     * Le détail d'une partie : ses joueurs par place et ses manches terminées.
     *
     * @return array{players: array<int, GamePlayer>, rounds: list<RamiRound>}
     */
    public function detail(RamiGame $game): array
    {
        $room = (int) $game->getRoom()->getId();

        return [
            'players' => $this->players->findByRoomIds([$room])[$room] ?? [],
            'rounds' => $this->entityManager->getRepository(RamiRound::class)->findBy(['game' => $game], ['number' => 'ASC']),
        ];
    }
}
