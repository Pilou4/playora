<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GamePlayerRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Historique et statistiques : un joueur (ou un robot) dans une partie.
 * Les places du salon sont libérées après la partie ; cette ligne, elle, reste.
 */
#[ORM\Entity(repositoryClass: GamePlayerRepository::class)]
#[ORM\Table(name: 'game_player')]
#[ORM\UniqueConstraint(name: 'uniq_game_player_position', columns: ['room_id', 'position'])]
#[ORM\Index(name: 'idx_game_player_user', columns: ['user_id'])]
class GamePlayer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GameRoom::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GameRoom $room;

    /** Vide pour un robot, ou si le compte a été supprimé. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    #[ORM\Column]
    private bool $bot;

    #[ORM\Column]
    private int $position;

    #[ORM\Column]
    private int $team;

    #[ORM\Column]
    private int $tricksWon = 0;

    #[ORM\Column]
    private int $pointsCollected = 0;

    #[ORM\Column]
    private int $contractsTaken = 0;

    #[ORM\Column]
    private int $contractsMade = 0;

    #[ORM\Column]
    private int $teamScore = 0;

    /** Vide tant que la partie n'est pas terminée. */
    #[ORM\Column(nullable: true)]
    private ?bool $won = null;

    public function __construct(GameRoom $room, GameRoomSeat $seat)
    {
        $this->room = $room;
        $this->user = $seat->getUser();
        $this->bot = $seat->isBot();
        $this->position = $seat->getPosition();
        $this->team = $seat->getTeam();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function isBot(): bool
    {
        return $this->bot;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getTeam(): int
    {
        return $this->team;
    }

    public function update(int $tricksWon, int $pointsCollected, int $teamScore): void
    {
        $this->tricksWon = $tricksWon;
        $this->pointsCollected = $pointsCollected;
        $this->teamScore = $teamScore;
    }

    public function recordContract(bool $made): void
    {
        ++$this->contractsTaken;

        if ($made) {
            ++$this->contractsMade;
        }
    }

    public function finish(bool $won): void
    {
        $this->won = $won;
    }

    public function getTricksWon(): int
    {
        return $this->tricksWon;
    }

    public function getPointsCollected(): int
    {
        return $this->pointsCollected;
    }

    public function getContractsTaken(): int
    {
        return $this->contractsTaken;
    }

    public function getContractsMade(): int
    {
        return $this->contractsMade;
    }

    public function getTeamScore(): int
    {
        return $this->teamScore;
    }

    public function hasWon(): ?bool
    {
        return $this->won;
    }
}
