<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PatienceGameRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une partie d'un jeu de patience (solitaire, Spider), de 2 à 5 joueurs : tous jouent la même donne à chaque manche
 * (une grille par joueur et par manche, voir PatienceBoard). Le meilleur total après la dernière manche l'emporte.
 * Le jeu est celui du salon. La table garde son nom d'origine (solitaire_game) : aucune migration.
 */
#[ORM\Entity(repositoryClass: PatienceGameRepository::class)]
#[ORM\Table(name: 'solitaire_game')]
class PatienceGame
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: GameRoom::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private GameRoom $room;

    /** Manche en cours (à partir de 1). */
    #[ORM\Column]
    private int $round = 1;

    #[ORM\Column]
    private int $rounds;

    /** Augmente à chaque fin de grille, de manche ou de partie : la page des autres joueurs sait qu'il faut se mettre à jour. */
    #[ORM\Column]
    private int $version = 1;

    #[ORM\Column]
    private bool $finished = false;

    /** Place gagnante (à partir de 1) ; null en cours de partie et en cas d'égalité. */
    #[ORM\Column(nullable: true)]
    private ?int $winnerPosition = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(GameRoom $room, int $rounds)
    {
        $this->room = $room;
        $this->rounds = $rounds;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function nextRound(): void
    {
        ++$this->round;
        $this->touch();
    }

    public function finish(?int $winnerPosition): void
    {
        $this->finished = true;
        $this->winnerPosition = $winnerPosition;
        $this->touch();
    }

    public function touch(): void
    {
        ++$this->version;
    }

    public function isLastRound(): bool
    {
        return $this->round >= $this->rounds;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoom(): GameRoom
    {
        return $this->room;
    }

    public function getRound(): int
    {
        return $this->round;
    }

    public function getRounds(): int
    {
        return $this->rounds;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    public function getWinnerPosition(): ?int
    {
        return $this->winnerPosition;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
