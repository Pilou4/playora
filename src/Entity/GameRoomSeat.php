<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GameRoomSeatRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une place autour de la table. Au début de la partie, les joueurs sont répartis au hasard :
 * les places 1 et 3 forment alors une équipe, 2 et 4 l'autre.
 */
#[ORM\Entity(repositoryClass: GameRoomSeatRepository::class)]
#[ORM\Table(name: 'game_room_seat')]
#[ORM\UniqueConstraint(name: 'uniq_game_room_seat_position', columns: ['room_id', 'position'])]
#[ORM\UniqueConstraint(name: 'uniq_game_room_seat_user', columns: ['room_id', 'user_id'])]
class GameRoomSeat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GameRoom::class, inversedBy: 'seats')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GameRoom $room;

    #[ORM\Column]
    private int $position;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    /** Place tenue par un robot (mode test seulement, voir PLAYORA_BOTS). */
    #[ORM\Column(options: ['default' => false])]
    private bool $bot = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $joinedAt = null;

    /** Dernier signal de présence : sans signal, la place est libérée (voir GameRoomManager). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    public function __construct(GameRoom $room, int $position)
    {
        $this->room = $room;
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoom(): GameRoom
    {
        return $this->room;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getTeam(): int
    {
        return 1 === $this->position % 2 ? 1 : 2;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function isFree(): bool
    {
        return null === $this->user && !$this->bot;
    }

    public function isBot(): bool
    {
        return $this->bot;
    }

    public function occupyWithBot(): void
    {
        $this->user = null;
        $this->bot = true;
        $this->joinedAt = new \DateTimeImmutable();
        $this->lastSeenAt = null;
    }

    public function occupy(User $user): void
    {
        $this->user = $user;
        $this->bot = false;
        $this->joinedAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->joinedAt;
    }

    public function markSeen(): void
    {
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function release(): void
    {
        $this->user = null;
        $this->bot = false;
        $this->joinedAt = null;
        $this->lastSeenAt = null;
    }

    public function getJoinedAt(): ?\DateTimeImmutable
    {
        return $this->joinedAt;
    }
}
