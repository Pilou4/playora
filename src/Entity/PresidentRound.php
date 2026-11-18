<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une manche de Président terminée : l'ordre dans lequel les joueurs ont fini, et les points de chaque place.
 */
#[ORM\Entity]
#[ORM\Table(name: 'president_round')]
class PresidentRound
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PresidentGame::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PresidentGame $game;

    #[ORM\Column]
    private int $number;

    /** Places (à partir de 0) dans l'ordre de fin : la première est Président, la dernière Pauvre. @var list<int> */
    #[ORM\Column(name: 'finish_order', type: Types::JSON)]
    private array $order;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $points;

    #[ORM\Column]
    private \DateTimeImmutable $playedAt;

    /**
     * @param list<int> $order
     * @param list<int> $points
     */
    public function __construct(PresidentGame $game, int $number, array $order, array $points)
    {
        $this->game = $game;
        $this->number = $number;
        $this->order = $order;
        $this->points = $points;
        $this->playedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGame(): PresidentGame
    {
        return $this->game;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    /**
     * @return list<int>
     */
    public function getOrder(): array
    {
        return $this->order;
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
