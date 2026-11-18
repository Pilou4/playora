<?php

declare(strict_types=1);

namespace App\Entity;

use App\Game\Tarot\TarotContract;
use App\Game\Tarot\TarotDealResult;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une donne de tarot terminée : pour l'historique de la partie et les statistiques de l'admin.
 * Les places sont numérotées à partir de 1 (de 1 à 3, 4 ou 5 selon le nombre de joueurs).
 */
#[ORM\Entity]
#[ORM\Table(name: 'tarot_deal')]
class TarotDeal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TarotGame::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TarotGame $game;

    #[ORM\Column]
    private int $number;

    #[ORM\Column]
    private int $dealer;

    #[ORM\Column]
    private int $taker;

    #[ORM\Column(length: 20, enumType: TarotContract::class)]
    private TarotContract $contract;

    #[ORM\Column]
    private bool $made;

    /** Points du preneur, en demi-points. */
    #[ORM\Column]
    private int $takerHalfPoints;

    #[ORM\Column]
    private int $bouts;

    /** Valeur de la donne, avant les coefficients de chaque place (voir TarotScorer). */
    #[ORM\Column]
    private int $value;

    /** Points marqués par chaque place. @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $scored;

    /** Petit au bout : 1 le preneur, 2 la défense, null personne. */
    #[ORM\Column(nullable: true)]
    private ?int $petitAuBout;

    #[ORM\Column]
    private bool $chelem;

    #[ORM\Column]
    private \DateTimeImmutable $playedAt;

    public function __construct(TarotGame $game, TarotDealResult $deal)
    {
        $this->game = $game;
        $this->number = $deal->number;
        $this->dealer = $deal->dealer + 1;
        $this->taker = $deal->taker + 1;
        $this->contract = $deal->contract;
        $this->made = $deal->made;
        $this->takerHalfPoints = $deal->takerHalfPoints;
        $this->bouts = $deal->bouts;
        $this->value = $deal->value;
        $this->scored = $deal->scored;
        $this->petitAuBout = null === $deal->petitAuBout ? null : $deal->petitAuBout + 1;
        $this->chelem = null !== $deal->chelem;
        $this->playedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGame(): TarotGame
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

    public function getTaker(): int
    {
        return $this->taker;
    }

    public function getContract(): TarotContract
    {
        return $this->contract;
    }

    public function isMade(): bool
    {
        return $this->made;
    }

    public function getTakerPoints(): float
    {
        return $this->takerHalfPoints / 2;
    }

    public function getBouts(): int
    {
        return $this->bouts;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    /**
     * @return list<int>
     */
    public function getScored(): array
    {
        return $this->scored;
    }

    public function getPetitAuBout(): ?int
    {
        return $this->petitAuBout;
    }

    public function isChelem(): bool
    {
        return $this->chelem;
    }

    public function getPlayedAt(): \DateTimeImmutable
    {
        return $this->playedAt;
    }
}
