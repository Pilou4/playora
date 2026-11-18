<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CardBack;
use App\Enum\CardSet;
use App\Enum\Carpet;
use App\Enum\Game;
use App\Enum\RoomStatus;
use App\Game\Belote\Variant;
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
    public const int TURN_SECONDS = 15;
    public const int BID_SECONDS = 15;

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

    /** Variante choisie à la création (belote, coinche, contrée). */
    #[ORM\Column(length: 20, enumType: Variant::class, options: ['default' => 'classique'])]
    private Variant $variant = Variant::Classic;

    /** Tapis et jeu de cartes réglés dans l'admin au moment de la création : une partie en cours ne change pas d'apparence. */
    #[ORM\Column(length: 20, enumType: Carpet::class, options: ['default' => 'lagon'])]
    private Carpet $carpet = Carpet::Lagon;

    #[ORM\Column(length: 20, enumType: CardSet::class, options: ['default' => 'moderne'])]
    private CardSet $cardSet = CardSet::Modern;

    #[ORM\Column(length: 20, enumType: CardBack::class, options: ['default' => 'moderne'])]
    private CardBack $cardBack = CardBack::Modern;

    /** Temps pour prendre ou passer aux enchères, en secondes. */
    #[ORM\Column(options: ['default' => self::BID_SECONDS])]
    private int $bidSeconds = self::BID_SECONDS;

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

    public function __construct(Game $game, User $host, string $inviteCode, int $targetPoints, RoomVisibility $visibility, bool $announcements, int $turnSeconds = self::TURN_SECONDS, int $bidSeconds = self::BID_SECONDS, Variant $variant = Variant::Classic, ?int $playerCount = null)
    {
        $this->variant = $variant;
        $this->turnSeconds = $turnSeconds;
        $this->bidSeconds = $bidSeconds;
        $this->game = $game;
        $this->host = $host;
        $this->inviteCode = $inviteCode;
        $this->targetPoints = $targetPoints;
        $this->visibility = $visibility;
        $this->announcements = $announcements;
        // Tarot : 3, 4 ou 5 joueurs ; les autres jeux : le nombre du jeu
        $this->playerCount = $playerCount ?? $game->playerCount();
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

    public function getBidSeconds(): int
    {
        return $this->bidSeconds;
    }

    public function getVariant(): Variant
    {
        return $this->variant;
    }

    public function getCarpet(): Carpet
    {
        return $this->carpet;
    }

    public function getCardSet(): CardSet
    {
        return $this->cardSet;
    }

    /**
     * Apparence choisie dans l'admin, fixée à la création du salon.
     */
    public function dress(Carpet $carpet, CardSet $cardSet, CardBack $cardBack = CardBack::Modern): void
    {
        $this->carpet = $carpet;
        $this->cardSet = $cardSet;
        $this->cardBack = $cardBack;
    }

    public function getCardBack(): CardBack
    {
        return $this->cardBack;
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
