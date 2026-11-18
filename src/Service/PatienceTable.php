<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GamePlayer;
use App\Entity\GameRoom;
use App\Entity\GameRoomSeat;
use App\Entity\PatienceBoard;
use App\Entity\PatienceGame;
use App\Entity\User;
use App\Game\Patience\PatienceException;
use App\Game\Patience\PatienceRules;
use App\Game\Patience\PatienceRulesFactory;
use App\Game\Patience\PatienceState;
use App\Repository\GamePlayerRepository;
use App\Repository\PatienceBoardRepository;
use App\Repository\PatienceGameRepository;
use Doctrine\ORM\EntityManagerInterface;
use Random\Randomizer;

/**
 * Un duel d'un jeu de patience (solitaire, Spider), de 2 à 5 joueurs, sans robots : tout ce qui ne dépend pas du jeu.
 * Chacun joue à son rythme : la partie du créateur commence dès la création du salon, chaque joueur qui prend
 * une place joue aussitôt la même donne (même graine), avec ses 5 minutes. Le salon reste ouvert tant qu'il a des
 * places libres ; toutes les places prises et toutes les grilles terminées, le meilleur score gagne.
 * Chaque coup passe dans une transaction qui verrouille la partie. Les coups propres à chaque jeu
 * (SolitaireTable, SpiderTable) passent par act().
 */
final readonly class PatienceTable
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PatienceGameRepository $games,
        private PatienceBoardRepository $boards,
        private GamePlayerRepository $players,
        private BeloteNotifier $notifier,
    ) {
    }

    /**
     * Création du salon d'un duel solo : la partie existe dès le départ (une seule manche), sans grille encore.
     */
    public function open(GameRoom $room): PatienceGame
    {
        $game = new PatienceGame($room, 1);
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }

    /**
     * Un joueur prend sa place (le créateur, puis chaque joueur qui rejoint) : sa grille est la même donne que
     * celle des autres, et son temps part aussitôt. Appelé dans la transaction du salon.
     */
    public function seat(GameRoom $room, GameRoomSeat $seat): void
    {
        $game = $this->games->findOneByRoom($room) ?? throw new \LogicException('Partie introuvable.');
        $position = $seat->getPosition() - 1;

        if (null !== $this->boards->findOne($game, $position, $game->getRound())) {
            return;
        }

        $boards = $this->boards->forRound($game, $game->getRound());
        $first = [] === $boards ? null : reset($boards);
        // La même graine pour tous : exactement les mêmes cartes, dans le même ordre
        $seed = null === $first ? (new Randomizer())->getInt(1, \PHP_INT_MAX) : (int) $first->getStateData()['seed'];

        $this->entityManager->persist(new GamePlayer($room, $seat));
        $this->entityManager->persist(new PatienceBoard($game, $position, $game->getRound(), $this->rules($game)->deal($seed), new \DateTimeImmutable()));
        $game->touch();
    }

    /**
     * Un coup sur la grille du joueur, joué par $action avec le moteur du jeu. Temps écoulé : la grille est close
     * au lieu de jouer le coup. Toutes les cartes rangées : le jeu ajoute ses bonus (tout rangé, temps restant).
     *
     * @param \Closure(PatienceState, int): mixed $action reçoit la grille et les secondes qui restent
     *
     * @throws PatienceException
     */
    public function act(User $user, PatienceGame $game, \Closure $action, bool $auto = false): void
    {
        $position = $this->positionOf($user, $game) ?? throw new PatienceException('Vous n’êtes pas à cette table.');

        $this->transaction($game, function () use ($game, $position, $action, $auto): void {
            if ($game->isFinished()) {
                throw new PatienceException('La partie est terminée.');
            }

            $board = $this->boards->findOne($game, $position, $game->getRound()) ?? throw new PatienceException('Grille introuvable.');
            $now = new \DateTimeImmutable();

            if ($board->secondsBeforeStart($now) > 0) {
                throw new PatienceException('La partie n’a pas encore commencé.');
            }

            if ($board->isFinished()) {
                throw new PatienceException('Votre partie est terminée.');
            }

            $rules = $this->rules($game);
            $state = $rules->restore($board->getStateData());
            $seconds = (int) floor($board->secondsLeft($now));

            if (0 === $seconds) {
                $rules->finish($state, 0, false);
            } else {
                $action($state, $seconds);
                $rules->afterMove($state, $auto);

                // Toutes les cartes rangées : le moteur a clos la grille, il reste les bonus
                if ($state->isComplete()) {
                    $rules->finish($state, $seconds, true);
                }
            }

            $board->save($state);

            if ($board->isFinished()) {
                $game->touch();
                $this->resolveRound($game);
            }
        });
    }

    /**
     * Bouton « Arrêter » : la manche du joueur s'arrête (avec le bonus que prévoit le jeu pour le temps qui reste).
     *
     * @throws PatienceException
     */
    public function stop(User $user, PatienceGame $game): void
    {
        $this->act($user, $game, fn (PatienceState $state, int $secondsLeft) => $this->rules($game)->finish($state, $secondsLeft, false));
    }

    /**
     * Le temps d'une grille est écoulé (la mienne ou celle d'un autre joueur, s'il a fermé sa page) : elle est close,
     * et la manche passe à la suite si toutes les grilles sont terminées.
     *
     * @throws PatienceException
     */
    public function timeout(User $user, PatienceGame $game): void
    {
        $this->positionOf($user, $game) ?? throw new PatienceException('Vous n’êtes pas à cette table.');

        $this->transaction($game, function () use ($game): void {
            if (0 === $this->expire($game)) {
                throw new PatienceException('Le temps n’est pas encore écoulé.');
            }

            $this->resolveRound($game);
        });
    }

    /**
     * Le joueur quitte sa partie en cours (« Quitter », ou « précédent » du navigateur) : sa grille est close au score
     * du moment, plus le bonus du temps qui reste (comme « Arrêter ») ; ce score compte pour le duel comme celui des autres.
     *
     * @throws PatienceException
     */
    public function abandon(User $user, PatienceGame $game): void
    {
        $position = $this->positionOf($user, $game) ?? throw new PatienceException('Vous n’êtes pas à cette table.');

        $this->transaction($game, function () use ($game, $position): void {
            if ($game->isFinished()) {
                throw new PatienceException('La partie est déjà terminée.');
            }

            $board = $this->boards->findOne($game, $position, $game->getRound());

            if (null !== $board && !$board->isFinished()) {
                // Le temps non utilisé compte, comme avec « Arrêter » (rien avant le début du temps)
                $now = new \DateTimeImmutable();
                $this->close($game, $board, $board->secondsBeforeStart($now) > 0 ? 0 : (int) floor($board->secondsLeft($now)));
            }

            $game->touch();
            $this->resolveRound($game);
        });
    }

    /**
     * Place du joueur (à partir de 0), ou null s'il n'est pas à cette table.
     */
    public function positionOf(User $user, PatienceGame $game): ?int
    {
        $seat = $game->getRoom()->getSeatOf($user);

        return null === $seat ? null : $seat->getPosition() - 1;
    }

    /**
     * Les règles du jeu de cette partie (celui du salon).
     */
    public function rules(PatienceGame $game): PatienceRules
    {
        return PatienceRulesFactory::for($game->getRoom()->getGame());
    }

    /**
     * Après chaque grille terminée : les scores des joueurs sont à jour ; toutes les places prises et toutes
     * les grilles terminées, la partie se termine.
     */
    private function resolveRound(PatienceGame $game): void
    {
        $room = $game->getRoom();
        $totals = $this->totals($game);

        foreach ($this->players->findByRoomIndexed($room) as $seatPosition => $player) {
            $player->update(0, 0, $totals[$seatPosition - 1] ?? 0);
        }

        $boards = $this->boards->forRound($game, $game->getRound());

        if (\count($boards) < $room->getPlayerCount()) {
            return;
        }

        foreach ($boards as $board) {
            if (!$board->isFinished()) {
                return;
            }
        }

        $this->end($game, $this->leader($game));
    }

    /**
     * Le meilleur total parmi les joueurs encore en lice (ceux qui sont partis ne gagnent pas) ; null en cas d'égalité.
     */
    private function leader(PatienceGame $game): ?int
    {
        $totals = $this->totals($game);

        foreach ($this->players->findByRoomIndexed($game->getRoom()) as $seatPosition => $player) {
            if (false === $player->hasWon()) {
                unset($totals[$seatPosition - 1]);
            }
        }

        if ([] === $totals) {
            return null;
        }

        $leaders = array_keys($totals, max($totals), true);

        return 1 === \count($leaders) ? $leaders[0] : null;
    }

    private function end(PatienceGame $game, ?int $winner): void
    {
        $game->finish(null === $winner ? null : $winner + 1);
        $totals = $this->totals($game);

        foreach ($this->players->findByRoomIndexed($game->getRoom()) as $seatPosition => $player) {
            $player->update(0, 0, $totals[$seatPosition - 1] ?? 0);

            // Égalité : ni gagnant ni perdant ; un joueur parti a déjà perdu
            if (null !== $winner && false !== $player->hasWon()) {
                $player->finish($winner === $seatPosition - 1);
            }
        }

        $game->getRoom()->close();
    }

    /**
     * Ferme les grilles de la manche dont le temps est écoulé. Renvoie leur nombre.
     */
    private function expire(PatienceGame $game): int
    {
        $now = new \DateTimeImmutable('-1 second');
        $expired = 0;

        foreach ($this->boards->forRound($game, $game->getRound()) as $board) {
            if (!$board->isFinished() && 0.0 === $board->secondsBeforeStart($now) && $board->secondsLeft($now) <= 0) {
                $this->close($game, $board);
                ++$expired;
            }
        }

        if ($expired > 0) {
            $game->touch();
        }

        return $expired;
    }

    /** Ferme une grille : temps écoulé (sans bonus) ou joueur parti (le temps qui lui restait compte). */
    private function close(PatienceGame $game, PatienceBoard $board, int $secondsLeft = 0): void
    {
        $rules = $this->rules($game);
        $state = $rules->restore($board->getStateData());
        $rules->finish($state, max(0, $secondsLeft), false);
        $board->save($state);
    }

    /**
     * Total de chaque place, toutes manches terminées confondues.
     *
     * @return array<int, int>
     */
    private function totals(PatienceGame $game): array
    {
        // Les scores sont lus en base : les grilles modifiées dans cette transaction y sont d'abord écrites
        $this->entityManager->flush();
        $totals = array_fill(0, $game->getRoom()->getPlayerCount(), 0);

        foreach ($this->boards->scores($game) as $scores) {
            foreach ($scores as $position => $score) {
                $totals[$position] += $score ?? 0;
            }
        }

        return $totals;
    }

    /**
     * Verrouille la partie le temps de l'action (deux joueurs ne modifient jamais la même manche en même temps),
     * enregistre, puis prévient les autres joueurs si quelque chose a changé pour eux.
     */
    private function transaction(PatienceGame $game, \Closure $work): void
    {
        $version = $this->entityManager->wrapInTransaction(function () use ($game, $work): int {
            $this->games->lock($game);
            $before = $game->getVersion();
            $work();
            $this->entityManager->flush();

            return $game->getVersion() === $before ? 0 : $game->getVersion();
        });

        if ($version > 0) {
            $this->notifier->changed($game);
        }
    }
}
