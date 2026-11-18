<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un trou de Golf terminé : qui a retourné ses 6 cartes le premier, et les points de chaque place.
 */
#[ORM\Entity]
#[ORM\Table(name: 'golf_hole')]
class GolfHole
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GolfGame::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GolfGame $game;

    #[ORM\Column]
    private int $number;

    /** Place du donneur (à partir de 0). */
    #[ORM\Column]
    private int $dealer;

    /** Place qui a retourné ses 6 cartes la première (à partir de 0). */
    #[ORM\Column(nullable: true)]
    private ?int $closer;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $points;

    #[ORM\Column]
    private \DateTimeImmutable $playedAt;

    /**
     * @param list<int> $points
     */
    public function __construct(GolfGame $game, int $number, int $dealer, ?int $closer, array $points)
    {
        $this->game = $game;
        $this->number = $number;
        $this->dealer = $dealer;
        $this->closer = $closer;
        $this->points = $points;
        $this->playedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGame(): GolfGame
    {
        return $this->game;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getDealer(): int
    {
        return $this->dealer;
    }

    public function getCloser(): ?int
    {
        return $this->closer;
    }

    /**
     * @return list<int>
     */
    public function getPoints(): array
    {
        return $this->points;
    }

    public function getPlayedAt(): \DateTimeImmutable
    {
        return $this->playedAt;
    }
}
