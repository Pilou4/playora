<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\GamePlayer;
use App\Entity\PatienceBoard;
use App\Entity\PatienceGame;
use App\Enum\Game;
use App\Game\Patience\PatienceRulesFactory;
use App\Repository\GamePlayerRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Chiffres d'un jeu de patience (solitaire, Spider) pour l'admin. Quelques requêtes groupées par page, jamais une par partie.
 */
final readonly class PatienceStatistics
{
    public const int PER_PAGE = 20;

    /** Colonnes que l'on peut trier dans la liste des parties (valeur de ?tri= => expression DQL). */
    public const array SORTS = [
        'numero' => 'g.id',
        'debut' => 'r.startedAt',
        'manches' => 'g.rounds',
        'etat' => 'g.winnerPosition',
    ];
    public const string DEFAULT_SORT = 'numero';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private GamePlayerRepository $players,
    ) {
    }

    /**
     * @return array{finished: int, playing: int, withBots: int, grids: int, complete: int, best: int, average: int}
     */
    public function summary(Game $game): array
    {
        $games = $this->entityManager->createQuery(
            'SELECT COUNT(g.id) AS total, SUM(CASE WHEN g.finished = true THEN 1 ELSE 0 END) AS finished FROM '.PatienceGame::class.' g JOIN g.room r WHERE r.game = :game',
        )->setParameter('game', $game)->getSingleResult();

        // Grilles terminées (cartes rangées, « Arrêter », temps écoulé ou abandon)
        $grids = $this->entityManager->createQuery(
            'SELECT COUNT(b.id) AS total, SUM(CASE WHEN b.complete = true THEN 1 ELSE 0 END) AS complete, MAX(b.score) AS best, AVG(b.score) AS average
             FROM '.PatienceBoard::class.' b JOIN b.game g JOIN g.room r WHERE b.finishedAt IS NOT NULL AND r.game = :game',
        )->setParameter('game', $game)->getSingleResult();

        $withBots = $this->entityManager->createQuery(
            'SELECT COUNT(DISTINCT r.id) FROM '.GamePlayer::class.' p JOIN p.room r WHERE p.bot = true AND r.game = :game',
        )->setParameter('game', $game)->getSingleScalarResult();

        $finished = (int) $games['finished'];

        return [
            'finished' => $finished,
            'playing' => (int) $games['total'] - $finished,
            'withBots' => (int) $withBots,
            'grids' => (int) $grids['total'],
            'complete' => (int) $grids['complete'],
            'best' => (int) $grids['best'],
            'average' => (int) round((float) $grids['average']),
        ];
    }

    public function pageCount(Game $game): int
    {
        $total = (int) $this->entityManager->createQuery('SELECT COUNT(g.id) FROM '.PatienceGame::class.' g JOIN g.room r WHERE r.game = :game')
            ->setParameter('game', $game)->getSingleScalarResult();

        return max(1, (int) ceil($total / self::PER_PAGE));
    }

    /**
     * Une page de parties triée, avec leurs joueurs et le total de chacun.
     * Le tri se fait en base (sur toutes les parties, pas seulement la page affichée).
     *
     * @param string $sort      une clé de SORTS (sinon le tri par défaut)
     * @param string $direction 'asc' ou 'desc'
     *
     * @return list<array{game: PatienceGame, players: array<int, GamePlayer>, totals: array<int, int>}>
     */
    public function page(Game $game, int $page, string $sort = self::DEFAULT_SORT, string $direction = 'desc'): array
    {
        $order = self::SORTS[$sort] ?? self::SORTS[self::DEFAULT_SORT];
        $direction = 'asc' === $direction ? 'ASC' : 'DESC';

        /** @var list<PatienceGame> $games */
        $games = $this->entityManager->createQuery(
            'SELECT g, r FROM '.PatienceGame::class.' g JOIN g.room r WHERE r.game = :game ORDER BY '.$order.' '.$direction.', g.id DESC',
        )->setParameter('game', $game)->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE)->getResult();

        if ([] === $games) {
            return [];
        }

        $players = $this->players->findByRoomIds(array_map(static fn (PatienceGame $game): int => (int) $game->getRoom()->getId(), $games));

        // Total de chaque place : les grilles terminées seulement
        $totals = [];
        $rows = $this->entityManager->createQuery(
            'SELECT IDENTITY(b.game) AS game, b.position, SUM(b.score) AS total FROM '.PatienceBoard::class.' b
             WHERE b.game IN (:games) AND b.finishedAt IS NOT NULL GROUP BY b.game, b.position',
        )->setParameter('games', $games)->getArrayResult();

        foreach ($rows as $row) {
            $totals[(int) $row['game']][(int) $row['position']] = (int) $row['total'];
        }

        return array_map(static fn (PatienceGame $game): array => [
            'game' => $game,
            'players' => $players[(int) $game->getRoom()->getId()] ?? [],
            'totals' => $totals[(int) $game->getId()] ?? [],
        ], $games);
    }

    /**
     * Le détail d'une partie : ses joueurs par place et toutes ses grilles, manche par manche,
     * avec les cartes rangées de chacune (sur 52 au solitaire, 104 au Spider).
     *
     * @return array{players: array<int, GamePlayer>, rounds: array<int, array<int, array{board: PatienceBoard, sorted: int, cards: int}>>}
     */
    public function detail(PatienceGame $game): array
    {
        $room = (int) $game->getRoom()->getId();
        $rules = PatienceRulesFactory::for($game->getRoom()->getGame());
        $rounds = [];

        foreach ($this->entityManager->getRepository(PatienceBoard::class)->findBy(['game' => $game], ['round' => 'ASC', 'position' => 'ASC']) as $board) {
            $state = $rules->restore($board->getStateData());
            $rounds[$board->getRound()][$board->getPosition()] = ['board' => $board, 'sorted' => $state->sortedCards(), 'cards' => $state->cardCount()];
        }

        return [
            'players' => $this->players->findByRoomIds([$room])[$room] ?? [],
            'rounds' => $rounds,
        ];
    }
}
