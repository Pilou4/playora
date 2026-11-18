<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GamePlayer;
use App\Entity\GameRoom;
use App\Entity\HuitGame;
use App\Entity\HuitRound;
use App\Entity\User;
use App\Enum\RoomStatus;
use App\Game\Huit\HuitEngine;
use App\Game\Huit\HuitException;
use App\Game\Huit\HuitState;
use App\Repository\GamePlayerRepository;
use App\Repository\HuitGameRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Random\Randomizer;

/**
 * Une table de 8 américain : chaque coup est vérifié par le moteur, dans une transaction verrouillée ;
 * les robots jouent aussitôt après. À la fin d'une manche, les points s'ajoutent et la manche suivante
 * commence avec le donneur suivant ; la partie s'arrête quand un joueur atteint les points prévus
 * (300, 500 ou 1 000) : celui qui en a le moins gagne.
 */
final readonly class HuitTable
{
    /** Garde-fou : nombre maximal de tours de robots à la suite. */
    private const int MAX_BOT_TURNS = 3000;

    private HuitEngine $engine;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private HuitGameRepository $games,
        private GamePlayerRepository $players,
        private BeloteNotifier $notifier,
        private LoggerInterface $logger,
    ) {
        $this->engine = new HuitEngine();
    }

    /**
     * Début de la partie (appelé quand toutes les places sont prises, dans la transaction du salon).
     */
    public function create(GameRoom $room): HuitGame
    {
        $players = $room->getPlayerCount();
        $state = $this->engine->deal($this->seed(), $players, (new Randomizer())->getInt(0, $players - 1));

        foreach ($room->getSeats() as $seat) {
            $this->entityManager->persist(new GamePlayer($room, $seat));
        }

        $game = new HuitGame($room, $state);
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        $this->runBots($game, $state);
        $this->save($game, $state, true);

        return $game;
    }

    /**
     * Poser une carte (avec un 8 : la couleur choisie ; « announce » : le joueur a dit « Carte ! »).
     *
     * @throws HuitException
     */
    public function play(User $user, HuitGame $game, string $card, ?string $suit, bool $announce): void
    {
        $this->act($user, $game, fn (HuitState $state, int $position) => $this->engine->play($state, $position, $card, $suit, $announce));
    }

    /** @throws HuitException */
    public function draw(User $user, HuitGame $game): void
    {
        $this->act($user, $game, fn (HuitState $state, int $position) => $this->engine->draw($state, $position));
    }

    /** @throws HuitException */
    public function pass(User $user, HuitGame $game): void
    {
        $this->act($user, $game, fn (HuitState $state, int $position) => $this->engine->pass($state, $position));
    }

    /**
     * Temps écoulé pour le joueur dont c'est le tour : le serveur joue à sa place.
     * N'importe quel joueur de la table peut le demander, quand le temps est vraiment écoulé.
     *
     * @throws HuitException
     */
    public function timeout(User $user, HuitGame $game): void
    {
        $this->act($user, $game, function (HuitState $state) use ($game): void {
            if ($state->isOver() || $game->secondsLeft(new \DateTimeImmutable('-1 second')) > 0) {
                throw new HuitException('Le temps n’est pas encore écoulé.');
            }

            $this->engine->autoPlay($state);
        });
    }

    /**
     * Le joueur quitte la partie en cours : un robot prend sa place jusqu'à la fin, la partie lui est comptée perdue.
     * S'il ne reste plus que des robots, ils finissent la partie entre eux.
     *
     * @throws HuitException
     */
    public function abandon(User $user, HuitGame $game): void
    {
        $this->entityManager->wrapInTransaction(function () use ($user, $game): void {
            $this->games->lock($game);
            $room = $game->getRoom();
            $seat = $room->getSeatOf($user) ?? throw new HuitException('Vous n’êtes pas à cette table.');

            if (RoomStatus::Closed === $room->getStatus()) {
                throw new HuitException('La partie est déjà terminée.');
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
    public function positionOf(User $user, HuitGame $game): ?int
    {
        $seat = $game->getRoom()->getSeatOf($user);

        return null === $seat ? null : $seat->getPosition() - 1;
    }

    /**
     * @param \Closure(HuitState, int): void $action
     *
     * @throws HuitException
     */
    private function act(User $user, HuitGame $game, \Closure $action): void
    {
        $position = $this->positionOf($user, $game) ?? throw new HuitException('Vous n’êtes pas à cette table.');

        $this->entityManager->wrapInTransaction(function () use ($game, $position, $action): void {
            $this->games->lock($game);

            if (RoomStatus::Closed === $game->getRoom()->getStatus()) {
                throw new HuitException('La partie est terminée.');
            }

            $state = $game->getState();
            $turn = $state->turn;
            $top = $state->top();
            $round = $game->getRound();
            $action($state, $position);
            $this->resolveRound($game, $state);
            // Le tour a changé de main (même s'il revient au joueur après les robots), ou il rejoue après un 10 :
            // son temps repart de zéro
            $newTurn = $state->turn !== $turn || $game->getRound() !== $round || $state->top() !== $top;
            $this->runBots($game, $state);
            $this->save($game, $state, $newTurn || $state->turn !== $turn);
        });

        $this->notifier->changed($game);
    }

    /**
     * Les robots jouent tant que c'est leur tour (manches suivantes comprises, s'il ne reste que des robots).
     */
    private function runBots(HuitGame $game, HuitState &$state): void
    {
        $bots = [];

        foreach ($game->getRoom()->getSeats() as $seat) {
            if ($seat->isBot()) {
                $bots[] = $seat->getPosition() - 1;
            }
        }

        for ($i = 0; $i < self::MAX_BOT_TURNS && RoomStatus::Closed !== $game->getRoom()->getStatus() && \in_array($state->turn, $bots, true); ++$i) {
            try {
                $this->engine->autoPlay($state);
            } catch (\Throwable $exception) {
                // Un robot ne doit jamais bloquer une partie : il pioche, ou passe
                $this->logger->error('Robot de 8 américain en erreur : coup de secours joué.', ['exception' => $exception]);
                null === $state->drawn ? $this->engine->draw($state, $state->turn) : $this->engine->pass($state, $state->turn);
            }

            $this->resolveRound($game, $state);
        }
    }

    /**
     * Manche finie : elle est enregistrée, les points s'ajoutent ; la partie s'arrête ou une nouvelle manche commence.
     */
    private function resolveRound(HuitGame $game, HuitState &$state): void
    {
        if (!$state->isOver()) {
            return;
        }

        $this->entityManager->persist(new HuitRound($game, $game->getRound(), $state->dealer, $state->winner, $state->penalties));
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
    private function end(HuitGame $game, array $scores): void
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

    private function save(HuitGame $game, HuitState $state, bool $newTurn): void
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
