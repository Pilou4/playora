<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GamePlayer;
use App\Entity\GameRoom;
use App\Entity\TarotDeal;
use App\Entity\TarotGame;
use App\Entity\User;
use App\Game\Tarot\Poignee;
use App\Game\Tarot\TarotActionResult;
use App\Game\Tarot\TarotBot;
use App\Game\Tarot\TarotCard;
use App\Game\Tarot\TarotContract;
use App\Game\Tarot\TarotEngine;
use App\Game\Tarot\TarotException;
use App\Game\Tarot\TarotPhase;
use App\Game\Tarot\TarotRules;
use App\Game\Tarot\TarotState;
use App\Repository\GamePlayerRepository;
use App\Repository\TarotGameRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Random\Randomizer;

/**
 * Une table de tarot : chaque coup est vérifié par le moteur, dans une transaction verrouillée ;
 * les robots jouent aussitôt après ; les donnes terminées et les scores sont enregistrés.
 */
final readonly class TarotTable
{
    /** Garde-fou : nombre maximal d'actions de robots à la suite. */
    private const int MAX_BOT_ACTIONS = 600;

    private TarotEngine $engine;
    private TarotBot $bot;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TarotGameRepository $games,
        private GamePlayerRepository $players,
        private BeloteNotifier $notifier,
        private LoggerInterface $logger,
    ) {
        $this->engine = new TarotEngine();
        $this->bot = new TarotBot($this->engine);
    }

    /**
     * Début de la partie (appelé quand la 4e place est prise, dans la transaction du salon).
     */
    public function create(GameRoom $room): TarotGame
    {
        [$state, $result] = $this->engine->start($room->getTargetPoints(), (new Randomizer())->getInt(0, $room->getPlayerCount() - 1), $room->getPlayerCount());

        foreach ($room->getSeats() as $seat) {
            $this->entityManager->persist(new GamePlayer($room, $seat));
        }

        $game = new TarotGame($room, $state);
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        $result = $result->merge($this->runBots($room, $state));
        $this->save($game, $state, $result);

        return $game;
    }

    /**
     * @throws TarotException
     */
    public function bid(User $user, TarotGame $game, ?TarotContract $contract): TarotActionResult
    {
        return $this->act($user, $game, fn (TarotState $state, int $position): TarotActionResult => $this->engine->bid($state, $position, $contract));
    }

    /**
     * À 5 joueurs : le preneur appelle un Roi.
     *
     * @throws TarotException
     */
    public function call(User $user, TarotGame $game, TarotCard $card): TarotActionResult
    {
        return $this->act($user, $game, fn (TarotState $state, int $position): TarotActionResult => $this->engine->call($state, $position, $card));
    }

    /**
     * @param list<TarotCard> $cards
     *
     * @throws TarotException
     */
    public function discard(User $user, TarotGame $game, array $cards): TarotActionResult
    {
        return $this->act($user, $game, fn (TarotState $state, int $position): TarotActionResult => $this->engine->discard($state, $position, $cards));
    }

    /**
     * @throws TarotException
     */
    public function chelem(User $user, TarotGame $game): TarotActionResult
    {
        return $this->act($user, $game, fn (TarotState $state, int $position): TarotActionResult => $this->engine->chelem($state, $position));
    }

    /**
     * @throws TarotException
     */
    public function poignee(User $user, TarotGame $game, Poignee $poignee): TarotActionResult
    {
        return $this->act($user, $game, fn (TarotState $state, int $position): TarotActionResult => $this->engine->poignee($state, $position, $poignee));
    }

    /**
     * @throws TarotException
     */
    public function play(User $user, TarotGame $game, TarotCard $card): TarotActionResult
    {
        return $this->act($user, $game, fn (TarotState $state, int $position): TarotActionResult => $this->engine->play($state, $position, $card));
    }

    /**
     * Temps écoulé pour le joueur dont c'est le tour : le serveur joue à sa place
     * (passe aux enchères, Roi appelé au hasard, écart choisi comme un robot, carte permise au hasard pendant le jeu).
     *
     * @throws TarotException
     */
    public function timeout(User $user, TarotGame $game): TarotActionResult
    {
        return $this->act($user, $game, function (TarotState $state) use ($game): TarotActionResult {
            if (TarotPhase::Finished === $state->phase || $game->secondsLeft(new \DateTimeImmutable('-1 second')) > 0) {
                throw new TarotException('Le temps n’est pas encore écoulé.');
            }

            $turn = $state->turn;

            return match ($state->phase) {
                TarotPhase::Bidding => $this->engine->bid($state, $turn, null),
                TarotPhase::Call => $this->engine->call($state, $turn, $this->randomCard($this->engine->callable($state))),
                TarotPhase::Dog => $this->engine->discard($state, $turn, TarotBot::chooseDiscard($state->hands[$turn], TarotRules::dogSize($state->players))),
                default => $this->engine->play($state, $turn, $this->randomCard($state->playableCards($turn))),
            };
        });
    }

    /**
     * Le joueur quitte la partie en cours : un robot prend sa place jusqu'à la fin, la partie lui est comptée perdue.
     *
     * @throws TarotException
     */
    public function abandon(User $user, TarotGame $game): void
    {
        $this->entityManager->wrapInTransaction(function () use ($user, $game): void {
            $this->games->lock($game);
            $room = $game->getRoom();
            $seat = $room->getSeatOf($user) ?? throw new TarotException('Vous n’êtes pas à cette table.');
            $state = $game->getState();

            if (TarotPhase::Finished === $state->phase) {
                throw new TarotException('La partie est déjà terminée.');
            }

            $seat->occupyWithBot();
            $this->players->findByRoomIndexed($room)[$seat->getPosition()]?->finish(false);

            $result = new TarotActionResult([['type' => 'left', 'position' => $seat->getPosition() - 1, 'pseudo' => $user->getPseudo()]]);
            $humans = array_filter($room->getSeats(), static fn ($other): bool => null !== $other->getUser());

            for ($rounds = 0; TarotPhase::Finished !== $state->phase && ($rounds < 1 || [] === $humans) && $rounds < 50; ++$rounds) {
                $result = $result->merge($this->runBots($room, $state));
            }

            $this->save($game, $state, $result);
        });

        $this->notifier->changed($game);
    }

    /**
     * Place du joueur (0 à 3), ou null s'il n'est pas à cette table.
     */
    public function positionOf(User $user, TarotGame $game): ?int
    {
        $seat = $game->getRoom()->getSeatOf($user);

        return null === $seat ? null : $seat->getPosition() - 1;
    }

    private function act(User $user, TarotGame $game, \Closure $action): TarotActionResult
    {
        $position = $this->positionOf($user, $game) ?? throw new TarotException('Vous n’êtes pas à cette table.');

        $result = $this->entityManager->wrapInTransaction(function () use ($game, $position, $action): TarotActionResult {
            $this->games->lock($game);
            $state = $game->getState();
            $result = $action($state, $position)->merge($this->runBots($game->getRoom(), $state));
            $this->save($game, $state, $result);

            return $result;
        });

        $this->notifier->changed($game);

        return $result;
    }

    private function runBots(GameRoom $room, TarotState $state): TarotActionResult
    {
        $bots = [];

        foreach ($room->getSeats() as $seat) {
            if ($seat->isBot()) {
                $bots[] = $seat->getPosition() - 1;
            }
        }

        $result = new TarotActionResult();

        for ($i = 0; $i < self::MAX_BOT_ACTIONS && TarotPhase::Finished !== $state->phase && \in_array($state->turn, $bots, true); ++$i) {
            $result = $result->merge($this->botAct($state));
        }

        return $result;
    }

    /**
     * Un robot ne doit jamais bloquer une partie : en cas d'erreur, l'erreur est notée et il joue le coup le plus simple.
     */
    private function botAct(TarotState $state): TarotActionResult
    {
        try {
            return $this->bot->act($state);
        } catch (\Throwable $exception) {
            $this->logger->error('Robot de tarot en erreur : coup de secours joué.', ['exception' => $exception]);
            $turn = $state->turn;

            return match ($state->phase) {
                TarotPhase::Bidding => $this->engine->bid($state, $turn, null),
                TarotPhase::Call => $this->engine->call($state, $turn, $this->engine->callable($state)[0]),
                TarotPhase::Dog => $this->engine->discard($state, $turn, TarotBot::chooseDiscard($state->hands[$turn], TarotRules::dogSize($state->players))),
                default => $this->engine->play($state, $turn, $state->playableCards($turn)[0]),
            };
        }
    }

    /**
     * @param non-empty-list<TarotCard> $cards
     */
    private function randomCard(array $cards): TarotCard
    {
        return $cards[(new Randomizer())->getInt(0, \count($cards) - 1)];
    }

    private function save(TarotGame $game, TarotState $state, TarotActionResult $result): void
    {
        $room = $game->getRoom();
        $players = $this->players->findByRoomIndexed($room);
        $keepClock = [] !== $result->events && [] === array_filter($result->events, static fn (array $event): bool => !\in_array($event['type'], ['poignee', 'chelem'], true));
        $game->save($state, $result->events, $keepClock);

        foreach ($result->deals as $deal) {
            $this->entityManager->persist(new TarotDeal($game, $deal));
            ($players[$deal->taker + 1] ?? null)?->recordContract($deal->made);
        }

        foreach ($players as $seatPosition => $player) {
            $position = $seatPosition - 1;
            $player->update(0, 0, $state->scores[$position]);
            $left = $room->getSeat($seatPosition)?->getUser() !== $player->getUser();

            if (null !== $state->winner && !$left) {
                $player->finish($state->winner === $position);
            }
        }

        if (TarotPhase::Finished === $state->phase) {
            $room->close();
        }

        $this->entityManager->flush();
    }
}
