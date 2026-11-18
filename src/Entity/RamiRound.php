<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une manche de rami terminée : qui l'a finie, en « rami sec » ou non, et les pénalités de chaque place.
 */
#[ORM\Entity]
#[ORM\Table(name: 'rami_round')]
class RamiRound
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RamiGame::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RamiGame $game;

    #[ORM\Column]
    private int $number;

    /** Place du donneur (à partir de 0). */
    #[ORM\Column]
    private int $dealer;

    /** Place qui a fini la manche (à partir de 0) ; null si la pioche s'est épuisée. */
    #[ORM\Column(nullable: true)]
    private ?int $winner;

    #[ORM\Column]
    private bool $ramiSec;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $penalties;

    #[ORM\Column]
    private \DateTimeImmutable $playedAt;

    /**
     * @param list<int> $penalties
     */
    public function __construct(RamiGame $game, int $number, int $dealer, ?int $winner, bool $ramiSec, array $penalties)
    {
        $this->game = $game;
        $this->number = $number;
        $this->dealer = $dealer;
        $this->winner = $winner;
        $this->ramiSec = $ramiSec;
        $this->penalties = $penalties;
        $this->playedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGame(): RamiGame
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

    public function isRamiSec(): bool
    {
        return $this->ramiSec;
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
