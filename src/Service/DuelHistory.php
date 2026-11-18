<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GamePlayer;
use App\Entity\GameRoom;
use App\Entity\GameRoomSeat;
use App\Entity\PatienceBoard;
use App\Entity\User;
use App\Enum\Game;
use App\Enum\RoomStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Mes parties » : toutes les parties d'un joueur, en cours et terminées, avec le classement de chacun.
 * - Duels solo (solitaire, Spider, FreeCell…) : terminés quand chaque place a un joueur et que tous ont fini ;
 *   tant que la partie du joueur n'est pas finie, les scores des autres restent cachés.
 * - Jeux à plusieurs (belote, tarot, rami…) : en cours dès que la partie a commencé, terminés à la fin de la partie ;
 *   les scores sont ceux de la table (le moins de points gagne au rami, au 8 américain et au golf).
 * Quatre requêtes par page, jamais une par partie.
 */
final readonly class DuelHistory
{
    public const int PER_PAGE = 20;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Nombre de parties du joueur, terminées ou en cours.
     */
    public function count(User $user, bool $finished): int
    {
        return (int) self::parameters($this->entityManager->createQuery(
            'SELECT COUNT(r.id) FROM '.GameRoom::class.' r WHERE '.self::condition($finished),
        ), $user, $finished)->getSingleScalarResult();
    }

    public function pageCount(User $user, bool $finished): int
    {
        return max(1, (int) ceil($this->count($user, $finished) / self::PER_PAGE));
    }

    /**
     * Une page de duels, du plus récent au plus ancien.
     *
     * @return list<array{room: GameRoom, game: Game, status: string, mineFinished: bool, ranking: list<array{position: int, pseudo: string, me: bool, state: string, score: ?int}>}>
     */
    public function page(User $user, int $page, bool $finished): array
    {
        $order = $finished ? 'r.finishedAt DESC, r.id DESC' : 'r.createdAt DESC, r.id DESC';
        $ids = array_map('intval', array_column(self::parameters($this->entityManager->createQuery(
            'SELECT r.id FROM '.GameRoom::class.' r WHERE '.self::condition($finished).' ORDER BY '.$order,
        ), $user, $finished)
            ->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE)
            ->getScalarResult(), 'id'));

        if ([] === $ids) {
            return [];
        }

        /** @var list<GameRoom> $rooms */
        $rooms = $this->entityManager->createQuery(
            'SELECT r, s, u FROM '.GameRoom::class.' r LEFT JOIN r.seats s LEFT JOIN s.user u WHERE r.id IN (:ids) ORDER BY '.$order,
        )->setParameter('ids', $ids)->getResult();

        $boards = $this->boards($ids);
        $players = $this->players($ids);

        return array_map(fn (GameRoom $room): array => $room->getGame()->isPatience()
            ? $this->row($room, $boards[(int) $room->getId()] ?? [], $user)
            : $this->tableRow($room, $players[(int) $room->getId()] ?? [], $user), $rooms);
    }

    /**
     * Un duel du joueur, pour la page de son résultat ; null s'il n'y joue pas (ou si ce n'est pas un duel solo).
     *
     * @return array{room: GameRoom, game: Game, status: string, mineFinished: bool, ranking: list<array{position: int, pseudo: string, me: bool, state: string, score: ?int}>}|null
     */
    public function one(GameRoom $room, User $user): ?array
    {
        if (!$room->getGame()->isPatience() || null === $room->getSeatOf($user)) {
            return null;
        }

        return $this->row($room, $this->boards([(int) $room->getId()])[(int) $room->getId()] ?? [], $user);
    }

    /**
     * Le classement d'un duel tel que l’affichent la page du résultat, « Mes parties » et l’application mobile :
     * rang des joueurs qui ont fini (du meilleur au moins bon), ceux qui jouent encore, les places libres.
     *
     * @param array{room: GameRoom, game: Game, status: string, mineFinished: bool, ranking: list<array{position: int, pseudo: string, me: bool, state: string, score: ?int}>} $row
     *
     * @return array{id: int, game: string, gameLabel: string, status: string, mineFinished: bool, waiting: bool, ranking: list<array{position: int, rank: ?int, pseudo: string, me: bool, state: string, score: ?int, winner: bool}>}
     */
    public static function export(array $row): array
    {
        $ranking = [];
        $rank = 0;
        $previous = null;
        $scores = array_filter(array_column($row['ranking'], 'score'), static fn (?int $score): bool => null !== $score);
        $best = [] === $scores ? null : max($scores);
        // Duel solo : le gagnant a le meilleur score, une fois le duel terminé, s'il est seul à l'avoir.
        // Jeu à plusieurs : la table l'a désigné (son équipe, au besoin)
        $winner = 'termine' === $row['status'] && null !== $best && 1 === \count(array_keys($scores, $best, true));
        $patience = $row['game']->isPatience();

        foreach ($row['ranking'] as $index => $player) {
            if (null !== $player['score'] && $player['score'] !== $previous) {
                $rank = $index + 1;
                $previous = $player['score'];
            }

            $ranking[] = [
                ...$player,
                'rank' => null === $player['score'] ? null : $rank,
                'winner' => $patience ? $winner && $player['score'] === $best : true === ($player['won'] ?? null),
            ];
        }

        return [
            'id' => (int) $row['room']->getId(),
            'game' => $row['game']->value,
            'gameLabel' => $row['game']->label(),
            'status' => $row['status'],
            'mineFinished' => $row['mineFinished'],
            // Quelqu'un joue encore, ou une place attend un joueur : la page se met à jour toute seule
            'waiting' => 'termine' !== $row['status'] && [] !== array_filter($row['ranking'], static fn (array $player): bool => 'fini' !== $player['state']),
            'ranking' => $ranking,
        ];
    }

    /**
     * Les grilles des duels donnés, par duel puis par place (à partir de 0), en une requête.
     *
     * @param list<int> $ids
     *
     * @return array<int, array<int, PatienceBoard>>
     */
    private function boards(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $boards = [];

        foreach ($this->entityManager->createQuery(
            'SELECT b, g FROM '.PatienceBoard::class.' b JOIN b.game g WHERE IDENTITY(g.room) IN (:ids)',
        )->setParameter('ids', $ids)->getResult() as $board) {
            \assert($board instanceof PatienceBoard);
            $boards[(int) $board->getGame()->getRoom()->getId()][$board->getPosition()] = $board;
        }

        return $boards;
    }

    /**
     * @param array<int, PatienceBoard> $boards par place (à partir de 0)
     *
     * @return array{room: GameRoom, game: Game, status: string, mineFinished: bool, ranking: list<array{position: int, pseudo: string, me: bool, state: string, score: ?int}>}
     */
    private function row(GameRoom $room, array $boards, User $user): array
    {
        $mine = $room->getSeatOf($user);
        $now = new \DateTimeImmutable();
        // Finie, ou temps écoulé (page fermée avant la fin, personne ne l'a encore close)
        $over = static fn (?PatienceBoard $board): bool => null !== $board && ($board->isFinished() || ($board->secondsBeforeStart($now) <= 0.0 && $board->secondsLeft($now) <= 0.0));
        $mineFinished = null !== $mine && $over($boards[$mine->getPosition() - 1] ?? null);
        $ranking = [];

        foreach ($room->getSeats() as $seat) {
            $board = $boards[$seat->getPosition() - 1] ?? null;
            $isMe = $seat === $mine;
            $state = match (true) {
                null === $seat->getUser() => 'libre',
                $over($board) => 'fini',
                default => 'en-cours',
            };

            $ranking[] = [
                'position' => $seat->getPosition(),
                'pseudo' => $seat->getUser()?->getPseudo() ?? 'Place libre',
                'me' => $isMe,
                'state' => $state,
                // Les scores des autres : seulement une fois sa propre partie finie
                'score' => 'fini' === $state && ($isMe || $mineFinished) ? $board?->getScore() : null,
            ];
        }

        // Ceux qui ont fini, du meilleur score au moins bon ; puis ceux qui jouent ; puis les places libres
        usort($ranking, static fn (array $a, array $b): int => [self::order($a['state']), -($a['score'] ?? 0)] <=> [self::order($b['state']), -($b['score'] ?? 0)]);

        return [
            'room' => $room,
            'game' => $room->getGame(),
            // Terminé seulement quand tout le monde a joué (même règle que les onglets de « Mes parties »)
            'status' => match (true) {
                [] === array_filter($ranking, static fn (array $player): bool => 'fini' !== $player['state']) => 'termine',
                RoomStatus::Waiting === $room->getStatus() => 'ouvert',
                default => 'en-cours',
            },
            'mineFinished' => $mineFinished,
            'ranking' => $ranking,
        ];
    }

    /**
     * Une partie de jeu à plusieurs : les joueurs de la partie (et robots), du gagnant au dernier.
     *
     * @param list<GamePlayer> $players
     *
     * @return array{room: GameRoom, game: Game, status: string, mineFinished: bool, ranking: list<array{position: int, pseudo: string, me: bool, state: string, score: ?int, won: ?bool}>}
     */
    private function tableRow(GameRoom $room, array $players, User $user): array
    {
        $game = $room->getGame();
        $over = RoomStatus::Closed === $room->getStatus();
        // Rami, 8 américain, Golf : le moins de points gagne
        $lowest = $game->hasPenaltyRounds() || $game->isGolf();
        $ranking = array_map(static fn (GamePlayer $player): array => [
            'position' => $player->getPosition(),
            'pseudo' => $player->getUser()?->getPseudo() ?? 'Robot',
            'me' => $player->getUser() === $user,
            'state' => $over ? 'fini' : 'en-cours',
            'score' => $player->getTeamScore(),
            'won' => $player->hasWon(),
        ], $players);

        usort($ranking, static fn (array $a, array $b): int => [true === $b['won'], $lowest ? $a['score'] : -$a['score'], $a['position']]
            <=> [true === $a['won'], $lowest ? $b['score'] : -$b['score'], $b['position']]);

        return [
            'room' => $room,
            'game' => $game,
            'status' => $over ? 'termine' : 'en-cours',
            // Partie en cours où le joueur a encore sa place : il peut la reprendre
            'mineFinished' => $over || null === $room->getSeatOf($user),
            'ranking' => $ranking,
        ];
    }

    /**
     * Les joueurs des parties données, par partie, en une requête.
     *
     * @param list<int> $ids
     *
     * @return array<int, list<GamePlayer>>
     */
    private function players(array $ids): array
    {
        $players = [];

        foreach ($this->entityManager->createQuery(
            'SELECT p, u, IDENTITY(p.room) AS room FROM '.GamePlayer::class.' p LEFT JOIN p.user u WHERE IDENTITY(p.room) IN (:ids) ORDER BY p.position ASC',
        )->setParameter('ids', $ids)->getResult() as $result) {
            $player = $result[0];
            \assert($player instanceof GamePlayer);
            $players[(int) $result['room']][] = $player;
        }

        return $players;
    }

    /**
     * Les parties du joueur (assis au salon, ou joueur de la partie même s'il l'a quittée), terminées ou en cours.
     */
    private static function condition(bool $finished): string
    {
        $mine = '(EXISTS (SELECT ms.id FROM '.GameRoomSeat::class.' ms WHERE ms.room = r AND ms.user = :user)'
            .' OR EXISTS (SELECT mp.id FROM '.GamePlayer::class.' mp WHERE mp.room = r AND mp.user = :user))';
        // Jeu à plusieurs : seulement les parties commencées (un salon qui attend ses joueurs n'est pas une partie)
        $table = $finished
            ? 'r.status = :closed AND EXISTS (SELECT tp.id FROM '.GamePlayer::class.' tp WHERE tp.room = r)'
            : 'r.status = :playing';

        return $mine.' AND r.game IN (:games) AND ((r.game IN (:solo) AND '.self::statusCondition($finished).') OR (r.game NOT IN (:solo) AND '.$table.'))';
    }

    /**
     * @template T of \Doctrine\ORM\Query
     *
     * @param T $query
     *
     * @return T
     */
    private static function parameters(\Doctrine\ORM\Query $query, User $user, bool $finished): \Doctrine\ORM\Query
    {
        return $query->setParameter('user', $user)
            // Une grille dont le temps est écoulé compte comme finie (page fermée avant la fin)
            ->setParameter('expired', new \DateTimeImmutable(\sprintf('-%d seconds', PatienceBoard::SECONDS)))
            ->setParameter('games', array_map(static fn (Game $game): string => $game->value, Game::cases()))
            ->setParameter('solo', self::games())
            ->setParameter($finished ? 'closed' : 'playing', $finished ? RoomStatus::Closed : RoomStatus::Playing);
    }

    /**
     * Terminé : chaque place a un vrai joueur et chacun a fini sa partie (tous ont un score). Une place libre (ou tenue
     * par un robot des anciens tests), ou un joueur qui n'a pas fini : la partie est en cours. Même règle que row().
     */
    private static function statusCondition(bool $finished): string
    {
        $unplayed = 'EXISTS (SELECT ps.id FROM '.GameRoomSeat::class.' ps WHERE ps.room = r AND (ps.user IS NULL'
            .' OR NOT EXISTS (SELECT pb.id FROM '.PatienceBoard::class.' pb JOIN pb.game pg WHERE pg.room = r AND pb.position = ps.position - 1 AND (pb.finishedAt IS NOT NULL OR pb.startedAt < :expired))))';

        return $finished ? 'NOT '.$unplayed : $unplayed;
    }

    private static function order(string $state): int
    {
        return ['fini' => 0, 'en-cours' => 1, 'libre' => 2][$state];
    }

    /**
     * @return list<string>
     */
    private static function games(): array
    {
        return array_values(array_map(static fn (Game $game): string => $game->value, array_filter(Game::cases(), static fn (Game $game): bool => $game->isPatience())));
    }
}
