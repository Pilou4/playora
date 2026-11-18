<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GamePlayer;
use App\Entity\GameRoom;
use App\Entity\PresidentGame;
use App\Entity\PresidentRound;
use App\Entity\User;
use App\Enum\RoomStatus;
use App\Game\President\PresidentEngine;
use App\Game\President\PresidentException;
use App\Game\President\PresidentState;
use App\Repository\GamePlayerRepository;
use App\Repository\PresidentGameRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Random\Randomizer;

/**
 * Une table de Président : chaque coup est vérifié par le moteur, dans une transaction verrouillée ;
 * les robots jouent aussitôt après. À la fin d'une manche, les points s'ajoutent (1 par joueur battu) et la manche
 * suivante commence par l'échange des cartes ; la partie s'arrête quand un joueur atteint les points prévus
 * (2, 3 ou 4 par joueur) : celui qui en a le plus gagne (à égalité : la première place).
 */
final readonly class PresidentTable
{
    /** Garde-fou : nombre maximal de coups de robots à la suite. */
    private const int MAX_BOT_TURNS = 5000;

    private PresidentEngine $engine;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PresidentGameRepository $games,
        private GamePlayerRepository $players,
        private BeloteNotifier $notifier,
        private LoggerInterface $logger,
    ) {
        $this->engine = new PresidentEngine();
    }

    /**
     * Début de la partie (appelé quand toutes les places sont prises, dans la transaction du salon).
     */
    public function create(GameRoom $room): PresidentGame
    {
        $state = $this->engine->deal($this->seed(), $room->getPlayerCount());

        foreach ($room->getSeats() as $seat) {
            $this->entityManager->persist(new GamePlayer($room, $seat));
        }

        $game = new PresidentGame($room, $state);
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        $this->runBots($game, $state);
        $this->save($game, $state, true);

        return $game;
    }

    /**
     * Poser une ou plusieurs cartes de même valeur.
     *
     * @param list<string> $cards
     *
     * @throws PresidentException
     */
    public function play(User $user, PresidentGame $game, array $cards): void
    {
        $this->act($user, $game, fn (PresidentState $state, int $position) => $this->engine->play($state, $position, $cards));
    }

    /** @throws PresidentException */
    public function pass(User $user, PresidentGame $game): void
    {
        $this->act($user, $game, fn (PresidentState $state, int $position) => $this->engine->pass($state, $position));
    }

    /**
     * Échange : le Président ou le Vice-président rend les cartes de son choix.
     *
     * @param list<string> $cards
     *
     * @throws PresidentException
     */
    public function give(User $user, PresidentGame $game, array $cards): void
    {
        $this->act($user, $game, fn (PresidentState $state, int $position) => $this->engine->give($state, $position, $cards));
    }

    /**
     * Temps écoulé : le serveur joue à la place du joueur dont c'est le tour (ou rend les cartes de l'échange).
     * N'importe quel joueur de la table peut le demander, quand le temps est vraiment écoulé.
     *
     * @throws PresidentException
     */
    public function timeout(User $user, PresidentGame $game): void
    {
        $this->act($user, $game, function (PresidentState $state) use ($game): void {
            if ($state->isOver() || $game->secondsLeft(new \DateTimeImmutable('-1 second')) > 0) {
                throw new PresidentException('Le temps n’est pas encore écoulé.');
            }

            $this->engine->autoPlay($state);
        });
    }

    /**
     * Le joueur quitte la partie en cours : un robot prend sa place jusqu'à la fin, la partie lui est comptée perdue.
     *
     * @throws PresidentException
     */
    public function abandon(User $user, PresidentGame $game): void
    {
        $this->entityManager->wrapInTransaction(function () use ($user, $game): void {
            $this->games->lock($game);
            $room = $game->getRoom();
            $seat = $room->getSeatOf($user) ?? throw new PresidentException('Vous n’êtes pas à cette table.');

            if (RoomStatus::Closed === $room->getStatus()) {
                throw new PresidentException('La partie est déjà terminée.');
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
    public function positionOf(User $user, PresidentGame $game): ?int
    {
        $seat = $game->getRoom()->getSeatOf($user);

        return null === $seat ? null : $seat->getPosition() - 1;
    }

    /**
     * @param \Closure(PresidentState, int): void $action
     *
     * @throws PresidentException
     */
    private function act(User $user, PresidentGame $game, \Closure $action): void
    {
        $position = $this->positionOf($user, $game) ?? throw new PresidentException('Vous n’êtes pas à cette table.');

        $this->entityManager->wrapInTransaction(function () use ($game, $position, $action): void {
            $this->games->lock($game);

            if (RoomStatus::Closed === $game->getRoom()->getStatus()) {
                throw new PresidentException('La partie est terminée.');
            }

            $state = $game->getState();
            $turn = $state->turn;
            $phase = $state->phase;
            $round = $game->getRound();
            $action($state, $position);
            $this->resolveRound($game, $state);
            // Le tour a changé de main, l'échange s'est terminé ou une manche a commencé : le temps repart de zéro
            $newTurn = $state->turn !== $turn || $state->phase !== $phase || $game->getRound() !== $round;
            $this->runBots($game, $state);
            $this->save($game, $state, $newTurn || $state->turn !== $turn);
        });

        $this->notifier->changed($game);
    }

    /**
     * Les robots rendent leurs cartes d'échange, puis jouent tant que c'est leur tour
     * (manches suivantes comprises, s'il ne reste que des robots).
     */
    private function runBots(PresidentGame $game, PresidentState &$state): void
    {
        $bots = [];

        foreach ($game->getRoom()->getSeats() as $seat) {
            if ($seat->isBot()) {
                $bots[] = $seat->getPosition() - 1;
            }
        }

        for ($i = 0; $i < self::MAX_BOT_TURNS && RoomStatus::Closed !== $game->getRoom()->getStatus(); ++$i) {
            $giver = $this->botToGive($state, $bots);

            if (null === $giver && !(PresidentState::PLAY === $state->phase && \in_array($state->turn, $bots, true))) {
                return;
            }

            try {
                null === $giver
                    ? $this->engine->autoPlay($state)
                    : $this->engine->give($state, $giver, \array_slice($state->hands[$giver], 0, $state->toGive[$giver]));
            } catch (\Throwable $exception) {
                // Un robot ne doit jamais bloquer une partie
                $this->logger->error('Robot du Président en erreur : coup de secours joué.', ['exception' => $exception]);
                match (true) {
                    null !== $giver => $this->engine->autoExchange($state),
                    null !== $state->lastPlay() => $this->engine->pass($state, $state->turn),
                    default => $this->engine->play($state, $state->turn, [$state->hands[$state->turn][0]]),
                };
            }

            $this->resolveRound($game, $state);
        }
    }

    /**
     * @param list<int> $bots
     */
    private function botToGive(PresidentState $state, array $bots): ?int
    {
        if (PresidentState::EXCHANGE !== $state->phase) {
            return null;
        }

        foreach ($bots as $position) {
            if (($state->toGive[$position] ?? 0) > 0) {
                return $position;
            }
        }

        return null;
    }

    /**
     * Manche finie : elle est enregistrée, les points s'ajoutent ; la partie s'arrête ou une nouvelle manche commence.
     */
    private function resolveRound(PresidentGame $game, PresidentState &$state): void
    {
        if (!$state->isOver()) {
            return;
        }

        $this->entityManager->persist(new PresidentRound($game, $game->getRound(), $state->order, $state->points));
        $game->addPoints($state->points);
        $scores = $game->getScores();

        if (max($scores) >= $game->goal()) {
            $this->end($game, $scores);

            return;
        }

        $game->nextRound();
        $state = $this->engine->deal($this->seed(), $state->players, $state->order);
    }

    /**
     * Le plus de points gagne ; à égalité, la première place (celle qui a joué la première).
     *
     * @param list<int> $scores
     */
    private function end(PresidentGame $game, array $scores): void
    {
        $winner = min(array_keys($scores, max($scores), true));
        $game->finish($winner + 1);
        $room = $game->getRoom();

        foreach ($this->players->findByRoomIndexed($room) as $seatPosition => $player) {
            // Un joueur parti a déjà perdu
            if (false !== $player->hasWon()) {
                $player->finish($winner === $seatPosition - 1);
            }
        }

        $room->close();
    }

    private function save(PresidentGame $game, PresidentState $state, bool $newTurn): void
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
