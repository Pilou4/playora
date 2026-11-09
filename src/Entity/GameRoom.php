<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Game;
use App\Enum\RoomStatus;
use App\Enum\RoomVisibility;
use App\Repository\GameRoomRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Salon de jeu : d'abord en attente des joueurs, puis la partie.
 * Jamais supprimé : toutes les parties sont conservées.
 */
#[ORM\Entity(repositoryClass: GameRoomRepository::class)]
#[ORM\Table(name: 'game_room')]
#[ORM\Index(name: 'idx_game_room_list', columns: ['game', 'status', 'visibility'])]
#[ORM\UniqueConstraint(name: 'uniq_game_room_invite_code', columns: ['invite_code'])]
class GameRoom
{
    public const int ROUNDS = 1;
    public const int TURN_SECONDS = 20;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: Game::class)]
    private Game $game;

    #[ORM\Column(length: 20, enumType: RoomStatus::class)]
    private RoomStatus $status = RoomStatus::Waiting;

    #[ORM\Column(length: 20, enumType: RoomVisibility::class)]
    private RoomVisibility $visibility;

    #[ORM\Column(length: 8)]
    private string $inviteCode;

    #[ORM\Column]
    private int $playerCount;

    #[ORM\Column]
    private int $rounds = self::ROUNDS;

    #[ORM\Column]
    private int $targetPoints;

    #[ORM\Column]
    private bool $announcements;

    #[ORM\Column]
    private int $turnSeconds = self::TURN_SECONDS;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $host;

    /** @var Collection<int, GameRoomSeat> */
    #[ORM\OneToMany(targetEntity: GameRoomSeat::class, mappedBy: 'room', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $seats;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(Game $game, User $host, string $inviteCode, int $targetPoints, RoomVisibility $visibility, bool $announcements)
    {
        $this->game = $game;
        $this->host = $host;
        $this->inviteCode = $inviteCode;
        $this->targetPoints = $targetPoints;
        $this->visibility = $visibility;
        $this->announcements = $announcements;
        $this->playerCount = $game->playerCount();
        $this->createdAt = new \DateTimeImmutable();
        $this->seats = new ArrayCollection();

        for ($position = 1; $position <= $this->playerCount; ++$position) {
            $this->seats->add(new GameRoomSeat($this, $position));
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGame(): Game
    {
        return $this->game;
    }

    public function getStatus(): RoomStatus
    {
        return $this->status;
    }

    public function isWaiting(): bool
    {
        return RoomStatus::Waiting === $this->status;
    }

    public function getVisibility(): RoomVisibility
    {
        return $this->visibility;
    }

    public function isPublic(): bool
    {
        return RoomVisibility::Public === $this->visibility;
    }

    public function getInviteCode(): string
    {
        return $this->inviteCode;
    }

    public function getPlayerCount(): int
    {
        return $this->playerCount;
    }

    public function getRounds(): int
    {
        return $this->rounds;
    }

    public function getTargetPoints(): int
    {
        return $this->targetPoints;
    }

    public function hasAnnouncements(): bool
    {
        return $this->announcements;
    }

    public function getTurnSeconds(): int
    {
        return $this->turnSeconds;
    }

    public function getHost(): User
    {
        return $this->host;
    }

    /**
     * @return list<GameRoomSeat>
     */
    public function getSeats(): array
    {
        return array_values($this->seats->toArray());
    }

    public function getSeat(int $position): ?GameRoomSeat
    {
        foreach ($this->seats as $seat) {
            if ($seat->getPosition() === $position) {
                return $seat;
            }
        }

        return null;
    }

    public function getSeatOf(User $user): ?GameRoomSeat
    {
        foreach ($this->seats as $seat) {
            if ($seat->getUser() === $user) {
                return $seat;
            }
        }

        return null;
    }

    public function getOccupiedSeatCount(): int
    {
        return \count(array_filter($this->getSeats(), static fn (GameRoomSeat $seat): bool => !$seat->isFree()));
    }

    public function getFirstFreeSeat(): ?GameRoomSeat
    {
        foreach ($this->seats as $seat) {
            if ($seat->isFree()) {
                return $seat;
            }
        }

        return null;
    }

    public function isFull(): bool
    {
        return $this->getOccupiedSeatCount() === $this->playerCount;
    }

    /**
     * Plus aucun joueur humain (les robots seuls ne gardent pas un salon ouvert).
     */
    public function isEmpty(): bool
    {
        foreach ($this->seats as $seat) {
            if (null !== $seat->getUser()) {
                return false;
            }
        }

        return true;
    }

    public function start(): void
    {
        $this->status = RoomStatus::Playing;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function close(): void
    {
        $this->status = RoomStatus::Closed;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }
}
