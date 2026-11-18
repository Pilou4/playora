<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GamePlayer;
use App\Entity\GameRoom;
use App\Entity\GolfGame;
use App\Entity\GolfHole;
use App\Entity\User;
use App\Enum\RoomStatus;
use App\Game\Golf\GolfEngine;
use App\Game\Golf\GolfException;
use App\Game\Golf\GolfState;
use App\Repository\GamePlayerRepository;
use App\Repository\GolfGameRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Random\Randomizer;

/**
 * Une table de Golf : chaque coup est vérifié par le moteur, dans une transaction verrouillée ;
 * les robots jouent aussitôt après. À la fin d'un trou, les points s'ajoutent et le trou suivant
 * commence avec le donneur suivant ; après le nombre de trous prévu (9 ou 18), celui qui a le moins de points gagne.
 */
final readonly class GolfTable
{
    /** Garde-fou : nombre maximal de tours de robots à la suite. */
    private const int MAX_BOT_TURNS = 3000;

    private GolfEngine $engine;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private GolfGameRepository $games,
        private GamePlayerRepository $players,
        private BeloteNotifier $notifier,
        private LoggerInterface $logger,
    ) {
        $this->engine = new GolfEngine();
    }

    /**
     * Début de la partie (appelé quand toutes les places sont prises, dans la transaction du salon).
     */
    public function create(GameRoom $room): GolfGame
    {
        $players = $room->getPlayerCount();
        $state = $this->engine->deal($this->seed(), $players, (new Randomizer())->getInt(0, $players - 1));

        foreach ($room->getSeats() as $seat) {
            $this->entityManager->persist(new GamePlayer($room, $seat));
        }

        $game = new GolfGame($room, $state);
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        $this->runBots($game, $state);
        $this->save($game, $state, true);

        return $game;
    }

    /**
     * Début du trou : retourner une de ses 2 premières cartes.
     *
     * @throws GolfException
     */
    public function reveal(User $user, GolfGame $game, int $index): void
    {
        $this->act($user, $game, fn (GolfState $state, int $position) => $this->engine->reveal($state, $position, $index));
    }

    /** @throws GolfException */
    public function draw(User $user, GolfGame $game): void
    {
        $this->act($user, $game, fn (GolfState $state, int $position) => $this->engine->draw($state, $position));
    }

    /**
     * Prendre la carte de la défausse et l'échanger contre la carte à cette place.
     *
     * @throws GolfException
     */
    public function takeDiscard(User $user, GolfGame $game, int $index): void
    {
        $this->act($user, $game, fn (GolfState $state, int $position) => $this->engine->takeDiscard($state, $position, $index));
    }

    /**
     * Échanger la carte piochée contre la carte à cette place.
     *
     * @throws GolfException
     */
    public function swap(User $user, GolfGame $game, int $index): void
    {
        $this->act($user, $game, fn (GolfState $state, int $position) => $this->engine->swap($state, $position, $index));
    }

    /**
     * Jeter la carte piochée et retourner la carte cachée à cette place.
     *
     * @throws GolfException
     */
    public function discardAndReveal(User $user, GolfGame $game, int $index): void
    {
        $this->act($user, $game, fn (GolfState $state, int $position) => $this->engine->discardAndReveal($state, $position, $index));
    }

    /**
     * Temps écoulé pour le joueur dont c'est le tour : le serveur joue à sa place (au début du trou : il retourne
     * les cartes de ceux qui ne l'ont pas fait). N'importe quel joueur de la table peut le demander, quand le temps est vraiment écoulé.
     *
     * @throws GolfException
     */
    public function timeout(User $user, GolfGame $game): void
    {
        $this->act($user, $game, function (GolfState $state) use ($game): void {
            if ($state->isOver() || $game->secondsLeft(new \DateTimeImmutable('-1 second')) > 0) {
                throw new GolfException('Le temps n’est pas encore écoulé.');
            }

            $this->engine->autoPlay($state);
        });
    }

    /**
     * Le joueur quitte la partie en cours : un robot prend sa place jusqu'à la fin, la partie lui est comptée perdue.
     * S'il ne reste plus que des robots, ils finissent la partie entre eux.
     *
     * @throws GolfException
     */
    public function abandon(User $user, GolfGame $game): void
    {
        $this->entityManager->wrapInTransaction(function () use ($user, $game): void {
            $this->games->lock($game);
            $room = $game->getRoom();
            $seat = $room->getSeatOf($user) ?? throw new GolfException('Vous n’êtes pas à cette table.');

            if (RoomStatus::Closed === $room->getStatus()) {
                throw new GolfException('La partie est déjà terminée.');
            }

            $seat->occupyWithBot();
            $this->players->findByRoomIndexed($room)[$seat->getPosition()]?->finish(false);
            $state = $game->getState();
            $turn = $state->turn;
            $phase = $state->phase;
            $this->runBots($game, $state);
            $this->save($game, $state, $state->turn !== $turn || $state->phase !== $phase);
        });

        $this->notifier->changed($game);
    }

    /**
     * Place du joueur (à partir de 0), ou null s'il n'est pas à cette table.
     */
    public function positionOf(User $user, GolfGame $game): ?int
    {
        $seat = $game->getRoom()->getSeatOf($user);

        return null === $seat ? null : $seat->getPosition() - 1;
    }

    /**
     * @param \Closure(GolfState, int): void $action
     *
     * @throws GolfException
     */
    private function act(User $user, GolfGame $game, \Closure $action): void
    {
        $position = $this->positionOf($user, $game) ?? throw new GolfException('Vous n’êtes pas à cette table.');

        $this->entityManager->wrapInTransaction(function () use ($game, $position, $action): void {
            $this->games->lock($game);

            if (RoomStatus::Closed === $game->getRoom()->getStatus()) {
                throw new GolfException('La partie est terminée.');
            }

            $state = $game->getState();
            $turn = $state->turn;
            $phase = $state->phase;
            $hole = $game->getHole();
            $action($state, $position);
            $this->resolveHole($game, $state);
            // Le tour a changé de main (même s'il revient au joueur après les robots), le jeu commence après les
            // cartes retournées, ou un nouveau trou commence : le temps repart de zéro
            $newTurn = $state->turn !== $turn || $state->phase !== $phase || $game->getHole() !== $hole;
            $this->runBots($game, $state);
            $this->save($game, $state, $newTurn || $state->turn !== $turn || $state->phase !== $phase);
        });

        $this->notifier->changed($game);
    }

    /**
     * Les robots retournent leurs 2 cartes au début de chaque trou, puis jouent tant que c'est leur tour
     * (trous suivants compris, s'il ne reste que des robots).
     */
    private function runBots(GolfGame $game, GolfState &$state): void
    {
        $bots = [];

        foreach ($game->getRoom()->getSeats() as $seat) {
            if ($seat->isBot()) {
                $bots[] = $seat->getPosition() - 1;
            }
        }

        for ($i = 0; $i < self::MAX_BOT_TURNS && RoomStatus::Closed !== $game->getRoom()->getStatus(); ++$i) {
            if (GolfState::START === $state->phase) {
                foreach ($bots as $bot) {
                    $this->engine->autoPlay($state, $bot);
                }

                if (GolfState::START === $state->phase) {
                    return;
                }

                continue;
            }

            if (!\in_array($state->turn, $bots, true)) {
                return;
            }

            try {
                $this->engine->autoPlay($state);
            } catch (\Throwable $exception) {
                // Un robot ne doit jamais bloquer une partie : il pioche, puis jette la carte en retournant une carte cachée
                $this->logger->error('Robot de Golf en erreur : coup de secours joué.', ['exception' => $exception]);
                $this->rescue($state);
            }

            $this->resolveHole($game, $state);
        }
    }

    /**
     * Coup de secours d'un robot : la pioche, puis la carte jetée en retournant la première carte cachée
     * (ou échangée contre la première carte, s'il n'en a plus de cachée).
     */
    private function rescue(GolfState $state): void
    {
        $position = $state->turn;

        if (null === $state->drawn) {
            $this->engine->draw($state, $position);
        }

        $hidden = array_search(false, $state->up[$position], true);
        false === $hidden ? $this->engine->swap($state, $position, 0) : $this->engine->discardAndReveal($state, $position, $hidden);
    }

    /**
     * Trou fini : il est enregistré, les points s'ajoutent ; la partie s'arrête après le dernier trou, sinon un nouveau trou commence.
     */
    private function resolveHole(GolfGame $game, GolfState &$state): void
    {
        if (!$state->isOver()) {
            return;
        }

        $this->entityManager->persist(new GolfHole($game, $game->getHole(), $state->dealer, $state->closer, $state->points));
        $game->addPoints($state->points);

        if ($game->getHole() >= $game->getRoom()->getTargetPoints()) {
            $this->end($game, $game->getScores());

            return;
        }

        $game->nextHole();
        $state = $this->engine->deal($this->seed(), $state->players, ($state->dealer + 1) % $state->players);
    }

    /**
     * @param list<int> $scores
     */
    private function end(GolfGame $game, array $scores): void
    {
        $leaders = array_keys($scores, min($scores), true);
        $winner = 1 === \count($leaders) ? $leaders[0] : null;
        $game->finish(null === $winner ? null : $winner + 1);
        $room = $game->getRoom();

        foreach ($this->players->findByRoomIndexed($room) as $seatPosition => $player) {
            // Égalité : ni gagnant ni perdant ; un joueur parti a déjà perdu
            if (null !== $winner && false !== $player->hasWon()) {
                $player->finish($winner === $seatPosition - 1);
            }
        }

        $room->close();
    }

    private function save(GolfGame $game, GolfState $state, bool $newTurn): void
    {
        $game->save($state, $newTurn);

        foreach ($this->players->findByRoomIndexed($game->getRoom()) as $seatPosition => $player) {
            $player->update(0, 0, $game->getScores()[$seatPosition - 1] ?? 0);
        }

        $this->entityManager->flush();
    }

    private function seed(): int
    {
        return (new Randomizer())->getInt(1, \PHP_INT_MAX);
    }
}
