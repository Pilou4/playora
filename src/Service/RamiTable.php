<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GamePlayer;
use App\Entity\GameRoom;
use App\Entity\RamiGame;
use App\Entity\RamiRound;
use App\Entity\User;
use App\Enum\RoomStatus;
use App\Game\Rami\RamiBot;
use App\Game\Rami\RamiEngine;
use App\Game\Rami\RamiException;
use App\Game\Rami\RamiState;
use App\Repository\GamePlayerRepository;
use App\Repository\RamiGameRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Random\Randomizer;

/**
 * Une table de rami : chaque coup est vérifié par le moteur, dans une transaction verrouillée ;
 * les robots jouent aussitôt après. À la fin d'une manche, les pénalités s'ajoutent et la manche suivante
 * commence avec le donneur suivant ; la partie s'arrête quand un joueur atteint les points prévus
 * (300, 500 ou 1 000) : le moins pénalisé gagne.
 */
final readonly class RamiTable
{
    /** Garde-fou : nombre maximal de tours de robots à la suite. */
    private const int MAX_BOT_TURNS = 2000;

    private RamiEngine $engine;
    private RamiBot $bot;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RamiGameRepository $games,
        private GamePlayerRepository $players,
        private BeloteNotifier $notifier,
        private LoggerInterface $logger,
    ) {
        $this->engine = new RamiEngine();
        $this->bot = new RamiBot($this->engine);
    }

    /**
     * Début de la partie (appelé quand toutes les places sont prises, dans la transaction du salon).
     */
    public function create(GameRoom $room): RamiGame
    {
        $players = $room->getPlayerCount();
        $state = $this->engine->deal($this->seed(), $players, (new Randomizer())->getInt(0, $players - 1));

        foreach ($room->getSeats() as $seat) {
            $this->entityManager->persist(new GamePlayer($room, $seat));
        }

        $game = new RamiGame($room, $state);
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        $this->runBots($game, $state);
        $this->save($game, $state, true);

        return $game;
    }

    /** @throws RamiException */
    public function draw(User $user, RamiGame $game): void
    {
        $this->act($user, $game, fn (RamiState $state, int $position) => $this->engine->draw($state, $position));
    }

    /** @throws RamiException */
    public function takeDiscard(User $user, RamiGame $game): void
    {
        $this->act($user, $game, fn (RamiState $state, int $position) => $this->engine->takeDiscard($state, $position));
    }

    /** @throws RamiException */
    public function giveBack(User $user, RamiGame $game): void
    {
        $this->act($user, $game, fn (RamiState $state, int $position) => $this->engine->giveBack($state, $position));
    }

    /**
     * @param list<list<string>> $groups
     *
     * @throws RamiException
     */
    public function meld(User $user, RamiGame $game, array $groups): void
    {
        $this->act($user, $game, fn (RamiState $state, int $position) => $this->engine->meld($state, $position, $groups));
    }

    /** @throws RamiException */
    public function layOff(User $user, RamiGame $game, string $card, int $meld): void
    {
        $this->act($user, $game, fn (RamiState $state, int $position) => $this->engine->layOff($state, $position, $card, $meld));
    }

    /** @throws RamiException */
    public function swapJoker(User $user, RamiGame $game, string $card, int $meld): void
    {
        $this->act($user, $game, fn (RamiState $state, int $position) => $this->engine->swapJoker($state, $position, $card, $meld));
    }

    /** @throws RamiException */
    public function discard(User $user, RamiGame $game, string $card): void
    {
        $this->act($user, $game, fn (RamiState $state, int $position) => $this->engine->discard($state, $position, $card));
    }

    /**
     * Temps écoulé pour le joueur dont c'est le tour : le serveur joue à sa place (pioche, puis défausse la carte la moins utile).
     * N'importe quel joueur de la table peut le demander, quand le temps est vraiment écoulé.
     *
     * @throws RamiException
     */
    public function timeout(User $user, RamiGame $game): void
    {
        $this->act($user, $game, function (RamiState $state) use ($game): void {
            if ($state->isOver() || $game->secondsLeft(new \DateTimeImmutable('-1 second')) > 0) {
                throw new RamiException('Le temps n’est pas encore écoulé.');
            }

            $this->engine->autoPlay($state);
        }, anyPlayer: true);
    }

    /**
     * Le joueur quitte la partie en cours : un robot prend sa place jusqu'à la fin, la partie lui est comptée perdue.
     * S'il ne reste plus que des robots, ils finissent la partie entre eux.
     *
     * @throws RamiException
     */
    public function abandon(User $user, RamiGame $game): void
    {
        $this->entityManager->wrapInTransaction(function () use ($user, $game): void {
            $this->games->lock($game);
            $room = $game->getRoom();
            $seat = $room->getSeatOf($user) ?? throw new RamiException('Vous n’êtes pas à cette table.');

            if (null !== $game->getWinnerPosition() || RoomStatus::Closed === $room->getStatus()) {
                throw new RamiException('La partie est déjà terminée.');
            }

            $seat->occupyWithBot();
            $this->players->findByRoomIndexed($room)[$seat->getPosition()]?->finish(false);
            $state = $game->getState();
            $turn = $state->turn;
            $this->runBots($game, $state);
            $this->save($game, $state, $state->turn !== $turn);
        });

        $this->notifier->changed($game);
    }

    /**
     * Place du joueur (à partir de 0), ou null s'il n'est pas à cette table.
     */
    public function positionOf(User $user, RamiGame $game): ?int
    {
        $seat = $game->getRoom()->getSeatOf($user);

        return null === $seat ? null : $seat->getPosition() - 1;
    }

    /**
     * @param \Closure(RamiState, int): void $action
     *
     * @throws RamiException
     */
    private function act(User $user, RamiGame $game, \Closure $action, bool $anyPlayer = false): void
    {
        $position = $this->positionOf($user, $game) ?? throw new RamiException('Vous n’êtes pas à cette table.');

        $this->entityManager->wrapInTransaction(function () use ($game, $position, $action, $anyPlayer): void {
            $this->games->lock($game);

            if (RoomStatus::Closed === $game->getRoom()->getStatus()) {
                throw new RamiException('La partie est terminée.');
            }

            $state = $game->getState();
            $turn = $state->turn;
            $round = $game->getRound();
            $action($state, $anyPlayer ? $state->turn : $position);
            $this->resolveRound($game, $state);
            // Le tour a changé de main (même s'il revient au joueur après les robots) : son temps repart de zéro
            $newTurn = $state->turn !== $turn || $game->getRound() !== $round;
            $this->runBots($game, $state);
            $this->save($game, $state, $newTurn || $state->turn !== $turn);
        });

        $this->notifier->changed($game);
    }

    /**
     * Les robots jouent tant que c'est leur tour (manches suivantes comprises, s'il ne reste que des robots).
     */
    private function runBots(RamiGame $game, RamiState &$state): void
    {
        $bots = [];

        foreach ($game->getRoom()->getSeats() as $seat) {
            if ($seat->isBot()) {
                $bots[] = $seat->getPosition() - 1;
            }
        }

        for ($i = 0; $i < self::MAX_BOT_TURNS && RoomStatus::Closed !== $game->getRoom()->getStatus() && \in_array($state->turn, $bots, true); ++$i) {
            $this->botTurn($state);
            $this->resolveRound($game, $state);
        }
    }

    /**
     * Un robot ne doit jamais bloquer une partie : en cas d'erreur, l'erreur est notée et il joue le coup le plus simple.
     */
    private function botTurn(RamiState $state): void
    {
        try {
            $this->bot->play($state);
        } catch (\Throwable $exception) {
            $this->logger->error('Robot de rami en erreur : coup de secours joué.', ['exception' => $exception]);
            $this->engine->autoPlay($state);
        }
    }

    /**
     * Manche finie : elle est enregistrée, les pénalités s'ajoutent ; la partie s'arrête ou une nouvelle manche commence.
     */
    private function resolveRound(RamiGame $game, RamiState &$state): void
    {
        if (!$state->isOver()) {
            return;
        }

        $this->entityManager->persist(new RamiRound($game, $game->getRound(), $state->dealer, $state->winner, $state->ramiSec, $state->penalties));
        $game->addPenalties($state->penalties);
        $scores = $game->getScores();

        if (max($scores) >= $game->getRoom()->getTargetPoints()) {
            $this->end($game, $scores);

            return;
        }

        $game->nextRound();
        $state = $this->engine->deal($this->seed(), $state->players, ($state->dealer + 1) % $state->players);
    }

    /**
     * @param list<int> $scores
     */
    private function end(RamiGame $game, array $scores): void
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

    private function save(RamiGame $game, RamiState $state, bool $newTurn): void
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
