<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GameRoom;
use App\Entity\User;
use App\Enum\Game;
use App\Enum\RoomVisibility;
use App\Repository\GameRoomRepository;
use Doctrine\ORM\EntityManagerInterface;
use Random\Randomizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Toutes les règles des salons, vérifiées par le serveur (jamais par le navigateur) :
 * un seul salon à la fois par joueur, une personne par place, fermeture quand le
 * dernier joueur part, équipes tirées au sort quand toutes les places sont prises.
 */
final readonly class GameRoomManager
{
    /** Sans signal de présence pendant ce délai, la place est libérée. */
    public const int ABSENCE_SECONDS = 90;

    private const string CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const int CODE_LENGTH = 6;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private GameRoomRepository $rooms,
        private GameRoomNotifier $notifier,
        private BeloteTable $belote,
        #[Autowire(env: 'bool:PLAYORA_BOTS')]
        private bool $botsEnabled,
    ) {
    }

    /**
     * Robots activés (mode test, PLAYORA_BOTS=1 dans .env.local) : jamais en production.
     */
    public function botsEnabled(): bool
    {
        return $this->botsEnabled;
    }

    /**
     * Mode test : un joueur assis dans le salon y ajoute un robot à une place libre.
     *
     * @throws \DomainException
     */
    public function addBot(User $user, GameRoom $room, int $position): void
    {
        if (!$this->botsEnabled) {
            throw new \DomainException('Les robots ne sont pas activés.');
        }

        $this->entityManager->wrapInTransaction(function () use ($user, $room, $position): void {
            $this->rooms->lock($room);

            if (!$room->isWaiting() || null === $room->getSeatOf($user)) {
                throw new \DomainException('Asseyez-vous à cette table pour y ajouter un robot.');
            }

            $seat = $room->getSeat($position);

            if (null === $seat || !$seat->isFree()) {
                throw new \DomainException('Cette place vient d’être prise.');
            }

            $seat->occupyWithBot();
            $this->startIfFull($room);
        });

        $this->notifier->roomChanged($room);
    }

    /**
     * @throws \DomainException
     */
    public function create(User $host, Game $game, int $targetPoints, bool $announcements): GameRoom
    {
        if (!\in_array($targetPoints, $game->targetPointsChoices(), true)) {
            throw new \DomainException('Nombre de points non autorisé.');
        }

        $this->assertNotSeated($host);

        // Salons privés (sur invitation) : plus tard ; le code est déjà prévu en base
        $room = new GameRoom($game, $host, $this->newInviteCode(), $targetPoints, RoomVisibility::Public, $announcements);
        $room->getFirstFreeSeat()?->occupy($host);

        $this->entityManager->persist($room);
        $this->entityManager->flush();
        $this->notifier->roomChanged($room);

        return $room;
    }

    /**
     * Rejoindre un salon à une place libre (la première si aucune n'est choisie).
     * Les équipes sont tirées au sort au début : la place ne décide pas du partenaire.
     *
     * @throws \DomainException
     */
    public function join(User $user, GameRoom $room, ?int $position = null): void
    {
        $this->entityManager->wrapInTransaction(function () use ($user, $room, $position): void {
            $this->rooms->lock($room);

            if (!$room->isWaiting()) {
                throw new \DomainException('Ce salon n’accepte plus de joueurs.');
            }

            if (null !== $room->getSeatOf($user)) {
                return;
            }

            $this->assertNotSeated($user);
            $seat = null === $position ? $room->getFirstFreeSeat() : $room->getSeat($position);

            if (null === $seat || !$seat->isFree()) {
                throw new \DomainException('Cette place vient d’être prise.');
            }

            $seat->occupy($user);
            $this->startIfFull($room);
        });

        $this->notifier->roomChanged($room);
    }

    /**
     * @throws \DomainException
     */
    public function leave(User $user, GameRoom $room): void
    {
        $this->entityManager->wrapInTransaction(function () use ($user, $room): void {
            $this->rooms->lock($room);

            if (!$room->isWaiting()) {
                throw new \DomainException('La partie a déjà commencé.');
            }

            $seat = $room->getSeatOf($user) ?? throw new \DomainException('Vous n’êtes pas dans ce salon.');
            $seat->release();

            if ($room->isEmpty()) {
                $room->close();
            }
        });

        $this->notifier->roomChanged($room);
    }

    /**
     * Signal de présence envoyé par la page des salons (tous les onglets ouverts).
     */
    public function markPresent(User $user): void
    {
        $room = $this->rooms->findCurrentFor($user);

        if (null !== $room && $room->isWaiting()) {
            $room->getSeatOf($user)?->markSeen();
            $this->entityManager->flush();
        }
    }

    /**
     * Libère les places des joueurs partis sans quitter (onglets fermés, connexion perdue).
     */
    public function releaseAbsentPlayers(Game $game): void
    {
        $since = new \DateTimeImmutable(\sprintf('-%d seconds', self::ABSENCE_SECONDS));

        foreach ($this->rooms->findIdsWithAbsentPlayers($game, $since) as $id) {
            $room = $this->rooms->find($id);

            if (null === $room) {
                continue;
            }

            $this->entityManager->wrapInTransaction(function () use ($room, $since): void {
                $this->rooms->lock($room);

                if (!$room->isWaiting()) {
                    return;
                }

                foreach ($room->getSeats() as $seat) {
                    if (null !== $seat->getUser() && $seat->getLastSeenAt() < $since) {
                        $seat->release();
                    }
                }

                if ($room->isEmpty()) {
                    $room->close();
                }
            });

            $this->notifier->roomChanged($room);
        }
    }

    /**
     * 4 places prises : équipes tirées au sort, puis la partie commence.
     */
    private function startIfFull(GameRoom $room): void
    {
        if (!$room->isFull()) {
            return;
        }

        $this->drawTeams($room);
        $room->start();
        $this->entityManager->flush();
        $this->belote->create($room);
    }

    /**
     * Équipes au hasard : les joueurs (et robots) sont redistribués sur les places (1 et 3 contre 2 et 4).
     */
    private function drawTeams(GameRoom $room): void
    {
        $seats = $room->getSeats();
        // null = robot
        $players = (new Randomizer())->shuffleArray(array_map(static fn ($seat) => $seat->getUser(), $seats));

        foreach ($seats as $seat) {
            $seat->release();
        }
        // Places vidées d'abord : la base refuse un même joueur deux fois dans un salon
        $this->entityManager->flush();

        foreach ($seats as $index => $seat) {
            null === $players[$index] ? $seat->occupyWithBot() : $seat->occupy($players[$index]);
        }
    }

    private function assertNotSeated(User $user): void
    {
        if (null !== $this->rooms->findCurrentFor($user)) {
            throw new \DomainException('Vous êtes déjà dans un salon : quittez-le pour en rejoindre un autre.');
        }
    }

    private function newInviteCode(): string
    {
        $randomizer = new Randomizer();

        do {
            $code = $randomizer->getBytesFromString(self::CODE_ALPHABET, self::CODE_LENGTH);
        } while ($this->rooms->inviteCodeExists($code));

        return $code;
    }
}
