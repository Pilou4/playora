<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\GamePlayer;
use App\Entity\TarotDeal;
use App\Entity\TarotGame;
use App\Enum\Game;
use App\Game\Tarot\TarotContract;
use App\Repository\GamePlayerRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Chiffres du tarot pour l'admin. Quelques requêtes groupées par page, jamais une par partie.
 */
final readonly class TarotStatistics
{
    public const int PER_PAGE = 20;

    /** Colonnes que l'on peut trier dans la liste des parties (valeur de ?tri= => expression DQL). */
    public const array SORTS = [
        'numero' => 'g.id',
        'debut' => 'r.startedAt',
        'joueurs' => 'r.playerCount',
        'donnes' => 'dealCount',
        'etat' => 'g.winnerPosition',
    ];
    public const string DEFAULT_SORT = 'numero';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private GamePlayerRepository $players,
    ) {
    }

    /**
     * @return array{finished: int, playing: int, withBots: int, deals: int, made: int, failed: int, chelems: int, petitAuBout: int, contracts: array<string, int>}
     */
    public function summary(): array
    {
        $games = $this->entityManager->createQuery(
            'SELECT COUNT(g.id) AS total, SUM(CASE WHEN g.winnerPosition IS NULL THEN 0 ELSE 1 END) AS finished FROM '.TarotGame::class.' g',
        )->getSingleResult();

        $deals = $this->entityManager->createQuery(
            'SELECT COUNT(d.id) AS total,
                    SUM(CASE WHEN d.made = true THEN 1 ELSE 0 END) AS made,
                    SUM(CASE WHEN d.chelem = true THEN 1 ELSE 0 END) AS chelems,
                    SUM(CASE WHEN d.petitAuBout IS NULL THEN 0 ELSE 1 END) AS petitAuBout
             FROM '.TarotDeal::class.' d',
        )->getSingleResult();

        $contracts = array_fill_keys(array_map(static fn (TarotContract $contract): string => $contract->value, TarotContract::cases()), 0);
        $rows = $this->entityManager->createQuery('SELECT d.contract, COUNT(d.id) AS total FROM '.TarotDeal::class.' d GROUP BY d.contract')->getArrayResult();

        foreach ($rows as $row) {
            $contract = $row['contract'] instanceof TarotContract ? $row['contract']->value : (string) $row['contract'];
            $contracts[$contract] = (int) $row['total'];
        }

        $withBots = $this->entityManager->createQuery(
            'SELECT COUNT(DISTINCT r.id) FROM '.GamePlayer::class.' p JOIN p.room r WHERE p.bot = true AND r.game = :game',
        )->setParameter('game', Game::Tarot)->getSingleScalarResult();

        $finished = (int) $games['finished'];
        $total = (int) $deals['total'];
        $made = (int) $deals['made'];

        return [
            'finished' => $finished,
            'playing' => (int) $games['total'] - $finished,
            'withBots' => (int) $withBots,
            'deals' => $total,
            'made' => $made,
            'failed' => $total - $made,
            'chelems' => (int) $deals['chelems'],
            'petitAuBout' => (int) $deals['petitAuBout'],
            'contracts' => $contracts,
        ];
    }

    public function pageCount(): int
    {
        $total = (int) $this->entityManager->createQuery('SELECT COUNT(g.id) FROM '.TarotGame::class.' g')->getSingleScalarResult();

        return max(1, (int) ceil($total / self::PER_PAGE));
    }

    /**
     * Une page de parties triée, avec leurs joueurs et leur nombre de donnes.
     * Le tri se fait en base (sur toutes les parties, pas seulement la page affichée).
     *
     * @param string $sort      une clé de SORTS (sinon le tri par défaut)
     * @param string $direction 'asc' ou 'desc'
     *
     * @return list<array{game: TarotGame, players: array<int, GamePlayer>, deals: int}>
     */
    public function page(int $page, string $sort = self::DEFAULT_SORT, string $direction = 'desc'): array
    {
        $order = self::SORTS[$sort] ?? self::SORTS[self::DEFAULT_SORT];
        $direction = 'asc' === $direction ? 'ASC' : 'DESC';

        /** @var list<TarotGame> $games */
        $games = $this->entityManager->createQuery(
            'SELECT g, r,
                (SELECT COUNT(d.id) FROM '.TarotDeal::class.' d WHERE d.game = g) AS HIDDEN dealCount
             FROM '.TarotGame::class.' g JOIN g.room r
             ORDER BY '.$order.' '.$direction.', g.id DESC',
        )->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE)->getResult();

        if ([] === $games) {
            return [];
        }

        $players = $this->players->findByRoomIds(array_map(static fn (TarotGame $game): int => (int) $game->getRoom()->getId(), $games));

        $deals = [];
        $counts = $this->entityManager->createQuery(
            'SELECT IDENTITY(d.game) AS game, COUNT(d.id) AS total FROM '.TarotDeal::class.' d WHERE d.game IN (:games) GROUP BY d.game',
        )->setParameter('games', $games)->getArrayResult();

        foreach ($counts as $count) {
            $deals[(int) $count['game']] = (int) $count['total'];
        }

        return array_map(static fn (TarotGame $game): array => [
            'game' => $game,
            'players' => $players[(int) $game->getRoom()->getId()] ?? [],
            'deals' => $deals[(int) $game->getId()] ?? 0,
        ], $games);
    }

    /**
     * Le détail d'une partie : ses joueurs par place et toutes ses donnes.
     *
     * @return array{players: array<int, GamePlayer>, deals: list<TarotDeal>}
     */
    public function detail(TarotGame $game): array
    {
        $room = (int) $game->getRoom()->getId();

        return [
            'players' => $this->players->findByRoomIds([$room])[$room] ?? [],
            'deals' => $this->entityManager->getRepository(TarotDeal::class)->findBy(['game' => $game], ['number' => 'ASC']),
        ];
    }
}
