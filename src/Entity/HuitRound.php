<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une manche de 8 américain terminée : qui l'a finie, et les points de chaque place.
 */
#[ORM\Entity]
#[ORM\Table(name: 'huit_round')]
class HuitRound
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: HuitGame::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private HuitGame $game;

    #[ORM\Column]
    private int $number;

    /** Place du donneur (à partir de 0). */
    #[ORM\Column]
    private int $dealer;

    /** Place qui a fini la manche (à partir de 0). */
    #[ORM\Column(nullable: true)]
    private ?int $winner;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $penalties;

    #[ORM\Column]
    private \DateTimeImmutable $playedAt;

    /**
     * @param list<int> $penalties
     */
    public function __construct(HuitGame $game, int $number, int $dealer, ?int $winner, array $penalties)
    {
        $this->game = $game;
        $this->number = $number;
        $this->dealer = $dealer;
        $this->winner = $winner;
        $this->penalties = $penalties;
        $this->playedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGame(): HuitGame
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

    public function getWinner(): ?int
    {
        return $this->winner;
    }

    /**
     * @return list<int>
     */
    public function getPenalties(): array
    {
        return $this->penalties;
    }

    public function getPlayedAt(): \DateTimeImmutable
    {
        return $this->playedAt;
    }
}
