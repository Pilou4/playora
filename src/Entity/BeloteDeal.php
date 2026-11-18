<?php

declare(strict_types=1);

namespace App\Entity;

use App\Game\Belote\DealResult;
use Doctrine\ORM\Mapping as ORM;

/**
 * Historique : une donne jouée jusqu'au bout. Les places vont de 1 à 4 (comme les places du salon),
 * les équipes de 1 à 2 (places 1 et 3 contre 2 et 4).
 */
#[ORM\Entity]
#[ORM\Table(name: 'belote_deal')]
#[ORM\Index(name: 'idx_belote_deal_game', columns: ['game_id'])]
class BeloteDeal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BeloteGame::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BeloteGame $game;

    #[ORM\Column]
    private int $number;

    #[ORM\Column]
    private int $dealer;

    #[ORM\Column]
    private int $taker;

    #[ORM\Column(length: 10)]
    private string $trump;

    #[ORM\Column(length: 10)]
    private string $outcome;

    #[ORM\Column]
    private int $team1CardPoints;

    #[ORM\Column]
    private int $team2CardPoints;

    #[ORM\Column]
    private int $team1Scored;

    #[ORM\Column]
    private int $team2Scored;

    #[ORM\Column(nullable: true)]
    private ?int $beloteTeam;

    #[ORM\Column(nullable: true)]
    private ?int $capotTeam;

    /** Coinche et contrée : contrat annoncé (null en belote) et multiplicateur (coinché 2, surcoinché 4). */
    #[ORM\Column(nullable: true)]
    private ?int $contract = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $multiplier = 1;

    #[ORM\Column]
    private \DateTimeImmutable $playedAt;

    public function __construct(BeloteGame $game, DealResult $deal)
    {
        $this->game = $game;
        $this->number = $deal->number;
        $this->dealer = $deal->dealer + 1;
        $this->taker = $deal->taker + 1;
        // Couleur d'atout, ou « tout-atout » / « sans-atout » à la coinche
        $this->trump = $deal->trump?->value ?? $deal->mode->value;
        $this->outcome = $deal->outcome->value;
        [$this->team1CardPoints, $this->team2CardPoints] = $deal->cardPoints;
        [$this->team1Scored, $this->team2Scored] = $deal->scored;
        $this->beloteTeam = null === $deal->beloteTeam ? null : $deal->beloteTeam + 1;
        $this->capotTeam = null === $deal->capotTeam ? null : $deal->capotTeam + 1;
        $this->contract = $deal->contract;
        $this->multiplier = $deal->multiplier;
        $this->playedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGame(): BeloteGame
    {
        return $this->game;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    /** Place du donneur (1 à 4). */
    public function getDealer(): int
    {
        return $this->dealer;
    }

    /** Place du preneur (1 à 4). */
    public function getTaker(): int
    {
        return $this->taker;
    }

    public function getTrump(): string
    {
        return $this->trump;
    }

    /** Points des cartes ramassées par chaque équipe (1 et 2). */
    public function getCardPoints(int $team): int
    {
        return 1 === $team ? $this->team1CardPoints : $this->team2CardPoints;
    }

    /** Points marqués au score par chaque équipe (1 et 2). */
    public function getScored(int $team): int
    {
        return 1 === $team ? $this->team1Scored : $this->team2Scored;
    }

    public function getBeloteTeam(): ?int
    {
        return $this->beloteTeam;
    }

    public function getCapotTeam(): ?int
    {
        return $this->capotTeam;
    }

    public function getContract(): ?int
    {
        return $this->contract;
    }

    public function getMultiplier(): int
    {
        return $this->multiplier;
    }

    public function getPlayedAt(): \DateTimeImmutable
    {
        return $this->playedAt;
    }
}
