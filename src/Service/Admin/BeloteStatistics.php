<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\BeloteDeal;
use App\Entity\BeloteGame;
use App\Entity\GamePlayer;
use App\Enum\Game;
use App\Game\Belote\Outcome;
use App\Repository\GamePlayerRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Chiffres d'un jeu de la famille belote (belote, coinche, contrée) pour l'admin.
 * Quelques requêtes groupées par page, jamais une par partie.
 */
final readonly class BeloteStatistics
{
    public const int PER_PAGE = 20;

    /** Colonnes que l'on peut trier dans la liste des parties (valeur de ?tri= => expression DQL). */
    public const array SORTS = [
        'numero' => 'g.id',
        'debut' => 'r.startedAt',
        'equipe-1' => 'team1',
        'equipe-2' => 'team2',
        'score' => 'g.team1Score',
        'donnes' => 'dealCount',
        'etat' => 'g.winnerTeam',
    ];
    public const string DEFAULT_SORT = 'numero';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private GamePlayerRepository $players,
    ) {
    }

    /**
     * @return array{finished: int, playing: int, deals: int, made: int, failed: int, litige: int, capots: int, withBots: int}
     */
    public function summary(Game $game): array
    {
        $games = $this->entityManager->createQuery(
            'SELECT COUNT(g.id) AS total, SUM(CASE WHEN g.winnerTeam IS NULL THEN 0 ELSE 1 END) AS finished FROM '.BeloteGame::class.' g JOIN g.room r WHERE r.game = :game',
        )->setParameter('game', $game)->getSingleResult();

        $deals = $this->entityManager->createQuery(
            'SELECT COUNT(d.id) AS total,
                    SUM(CASE WHEN d.outcome = :made THEN 1 ELSE 0 END) AS made,
                    SUM(CASE WHEN d.outcome = :failed THEN 1 ELSE 0 END) AS failed,
                    SUM(CASE WHEN d.outcome = :litige THEN 1 ELSE 0 END) AS litige,
                    SUM(CASE WHEN d.capotTeam IS NULL THEN 0 ELSE 1 END) AS capots
             FROM '.BeloteDeal::class.' d JOIN d.game g JOIN g.room r WHERE r.game = :game',
        )->setParameters([
            'game' => $game,
            'made' => Outcome::Made->value,
            'failed' => Outcome::Failed->value,
            'litige' => Outcome::Litige->value,
        ])->getSingleResult();

        $withBots = $this->entityManager->createQuery(
            'SELECT COUNT(DISTINCT r.id) FROM '.GamePlayer::class.' p JOIN p.room r WHERE p.bot = true AND r.game = :game',
        )->setParameter('game', $game)->getSingleScalarResult();

        $finished = (int) $games['finished'];

        return [
            'finished' => $finished,
            'playing' => (int) $games['total'] - $finished,
            'deals' => (int) $deals['total'],
            'made' => (int) $deals['made'],
            'failed' => (int) $deals['failed'],
            'litige' => (int) $deals['litige'],
            'capots' => (int) $deals['capots'],
            'withBots' => (int) $withBots,
        ];
    }

    public function pageCount(Game $game): int
    {
        $total = (int) $this->entityManager->createQuery('SELECT COUNT(g.id) FROM '.BeloteGame::class.' g JOIN g.room r WHERE r.game = :game')
            ->setParameter('game', $game)->getSingleScalarResult();

        return max(1, (int) ceil($total / self::PER_PAGE));
    }

    /**
     * Une page de parties triée, avec leurs joueurs et leur nombre de donnes.
     * Le tri se fait en base (sur toutes les parties, pas seulement la page affichée).
     *
     * @param string $sort      une clé de SORTS (sinon le tri par défaut)
     * @param string $direction 'asc' ou 'desc'
     *
     * @return list<array{game: BeloteGame, players: array<int, GamePlayer>, deals: int}>
     */
    public function page(Game $game, int $page, string $sort = self::DEFAULT_SORT, string $direction = 'desc'): array
    {
        $order = self::SORTS[$sort] ?? self::SORTS[self::DEFAULT_SORT];
        $direction = 'asc' === $direction ? 'ASC' : 'DESC';
        $second = 'g.team1Score' === $order ? ', g.team2Score '.$direction : '';

        // Équipes : triées par le premier pseudo (les robots n'ont pas de pseudo) ; donnes : par leur nombre
        /** @var list<BeloteGame> $games */
        $games = $this->entityManager->createQuery(
            'SELECT g, r,
                (SELECT MIN(u1.pseudo) FROM '.GamePlayer::class.' p1 LEFT JOIN p1.user u1 WHERE p1.room = r AND p1.team = 1) AS HIDDEN team1,
                (SELECT MIN(u2.pseudo) FROM '.GamePlayer::class.' p2 LEFT JOIN p2.user u2 WHERE p2.room = r AND p2.team = 2) AS HIDDEN team2,
                (SELECT COUNT(d.id) FROM '.BeloteDeal::class.' d WHERE d.game = g) AS HIDDEN dealCount
             FROM '.BeloteGame::class.' g JOIN g.room r
             WHERE r.game = :game
             ORDER BY '.$order.' '.$direction.$second.', g.id DESC',
        )->setParameter('game', $game)->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE)->getResult();

        if ([] === $games) {
            return [];
        }

        $rooms = array_map(static fn (BeloteGame $game): int => (int) $game->getRoom()->getId(), $games);
        $players = $this->players->findByRoomIds($rooms);

        $deals = [];
        $counts = $this->entityManager->createQuery(
            'SELECT IDENTITY(d.game) AS game, COUNT(d.id) AS total FROM '.BeloteDeal::class.' d WHERE d.game IN (:games) GROUP BY d.game',
        )->setParameter('games', $games)->getArrayResult();

        foreach ($counts as $count) {
            $deals[(int) $count['game']] = (int) $count['total'];
        }

        return array_map(static fn (BeloteGame $game): array => [
            'game' => $game,
            'players' => $players[(int) $game->getRoom()->getId()] ?? [],
            'deals' => $deals[(int) $game->getId()] ?? 0,
        ], $games);
    }

    /**
     * Le détail d'une partie : ses joueurs par place et toutes ses donnes.
     *
     * @return array{players: array<int, GamePlayer>, deals: list<BeloteDeal>}
     */
    public function detail(BeloteGame $game): array
    {
        $room = (int) $game->getRoom()->getId();

        return [
            'players' => $this->players->findByRoomIds([$room])[$room] ?? [],
            'deals' => $this->entityManager->getRepository(BeloteDeal::class)->findBy(['game' => $game], ['number' => 'ASC']),
        ];
    }
}
