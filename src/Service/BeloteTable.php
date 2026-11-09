<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BeloteDeal;
use App\Entity\BeloteGame;
use App\Entity\GamePlayer;
use App\Entity\GameRoom;
use App\Entity\User;
use App\Game\Belote\ActionResult;
use App\Game\Belote\BeloteBot;
use App\Game\Belote\BeloteEngine;
use App\Game\Belote\BeloteException;
use App\Game\Belote\BeloteState;
use App\Game\Belote\Card;
use App\Game\Belote\Outcome;
use App\Game\Belote\Phase;
use App\Game\Belote\Suit;
use App\Repository\BeloteGameRepository;
use App\Repository\GamePlayerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Random\Randomizer;

/**
 * La table de belote côté serveur : applique le coup d'un joueur (après vérification par le moteur),
 * fait jouer les robots jusqu'au prochain joueur humain, enregistre l'état et les statistiques.
 */
final readonly class BeloteTable
{
    /** Garde-fou : nombre maximal d'actions de robots à la suite. */
    private const int MAX_BOT_ACTIONS = 400;

    private BeloteEngine $engine;
    private BeloteBot $bot;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private BeloteGameRepository $games,
        private GamePlayerRepository $players,
        private BeloteNotifier $notifier,
        private LoggerInterface $logger,
    ) {
        $this->engine = new BeloteEngine();
        $this->bot = new BeloteBot($this->engine);
    }

    /**
     * Début de la partie (appelé quand la 4e place est prise, dans la transaction du salon).
     */
    public function create(GameRoom $room): BeloteGame
    {
        [$state, $result] = $this->engine->start($room->getTargetPoints(), (new Randomizer())->getInt(0, 3));

        foreach ($room->getSeats() as $seat) {
            $this->entityManager->persist(new GamePlayer($room, $seat));
        }

        $game = new BeloteGame($room, $state);
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        $result = $result->merge($this->runBots($room, $state));
        $this->save($game, $state, $result);

        return $game;
    }

    /**
     * @throws BeloteException
     */
    public function bid(User $user, BeloteGame $game, bool $take, ?Suit $suit): ActionResult
    {
        return $this->act($user, $game, fn (BeloteState $state, int $position): ActionResult => $take
            ? $this->engine->take($state, $position, $suit)
            : $this->engine->pass($state, $position));
    }

    /**
     * @throws BeloteException
     */
    public function play(User $user, BeloteGame $game, Card $card): ActionResult
    {
        return $this->act($user, $game, fn (BeloteState $state, int $position): ActionResult => $this->engine->play($state, $position, $card));
    }

    /**
     * Temps écoulé pour le joueur dont c'est le tour : le serveur joue à sa place
     * (« je passe » aux enchères, une carte permise au hasard pendant le jeu).
     * N'importe quel joueur de la table peut le demander ; le serveur vérifie l'heure lui-même.
     *
     * @throws BeloteException
     */
    public function timeout(User $user, BeloteGame $game): ActionResult
    {
        return $this->act($user, $game, function (BeloteState $state) use ($game): ActionResult {
            // Une seconde de marge : l'horloge du navigateur peut avoir un peu d'avance
            if (Phase::Finished === $state->phase || $game->secondsLeft(new \DateTimeImmutable('-1 second')) > 0) {
                throw new BeloteException('Le temps n’est pas encore écoulé.');
            }

            $turn = $state->turn;

            if ($state->phase->isBidding()) {
                return $this->engine->pass($state, $turn);
            }

            $cards = $state->playableCards($turn);

            return $this->engine->play($state, $turn, $cards[(new Randomizer())->getInt(0, \count($cards) - 1)]);
        });
    }

    /**
     * Place du joueur (0 à 3), ou null s'il n'est pas à cette table.
     */
    public function positionOf(User $user, BeloteGame $game): ?int
    {
        $seat = $game->getRoom()->getSeatOf($user);

        return null === $seat ? null : $seat->getPosition() - 1;
    }

    /**
     * @param \Closure(BeloteState, int): ActionResult $action
     *
     * @throws BeloteException
     */
    private function act(User $user, BeloteGame $game, \Closure $action): ActionResult
    {
        $position = $this->positionOf($user, $game) ?? throw new BeloteException('Vous n’êtes pas à cette table.');

        $result = $this->entityManager->wrapInTransaction(function () use ($game, $position, $action): ActionResult {
            $this->games->lock($game);
            $state = $game->getState();
            $result = $action($state, $position)->merge($this->runBots($game->getRoom(), $state));
            $this->save($game, $state, $result);

            return $result;
        });

        $this->notifier->changed($game);

        return $result;
    }

    private function runBots(GameRoom $room, BeloteState $state): ActionResult
    {
        $bots = [];

        foreach ($room->getSeats() as $seat) {
            if ($seat->isBot()) {
                $bots[] = $seat->getPosition() - 1;
            }
        }

        $result = new ActionResult();

        for ($i = 0; $i < self::MAX_BOT_ACTIONS && Phase::Finished !== $state->phase && \in_array($state->turn, $bots, true); ++$i) {
            $result = $result->merge($this->botAct($state));
        }

        return $result;
    }

    /**
     * Un robot ne doit jamais bloquer une partie : en cas d'erreur, l'erreur est notée dans les logs
     * et il joue le coup le plus simple (passer aux enchères, première carte permise au jeu).
     */
    private function botAct(BeloteState $state): ActionResult
    {
        try {
            return $this->bot->act($state);
        } catch (\Throwable $exception) {
            $this->logger->error('Robot de belote en erreur : coup de secours joué.', ['exception' => $exception]);

            return $state->phase->isBidding()
                ? $this->engine->pass($state, $state->turn)
                : $this->engine->play($state, $state->turn, $state->playableCards($state->turn)[0]);
        }
    }

    /**
     * Enregistre l'état, les donnes terminées et les statistiques des joueurs.
     */
    private function save(BeloteGame $game, BeloteState $state, ActionResult $result): void
    {
        $room = $game->getRoom();
        $players = $this->players->findByRoomIndexed($room);
        $game->save($state, $result->events);

        foreach ($result->deals as $deal) {
            $this->entityManager->persist(new BeloteDeal($game, $deal));
            ($players[$deal->taker + 1] ?? null)?->recordContract(Outcome::Made === $deal->outcome);
        }

        foreach ($players as $seatPosition => $player) {
            $position = $seatPosition - 1;
            $player->update($state->playerTricks[$position], $state->playerPoints[$position], $state->scores[$position % 2]);

            if (null !== $state->winnerTeam) {
                $player->finish($state->winnerTeam === $position % 2);
            }
        }

        if (Phase::Finished === $state->phase) {
            $room->close();
        }

        $this->entityManager->flush();
    }
}
