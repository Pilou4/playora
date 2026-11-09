<?php

declare(strict_types=1);

namespace App\Entity;

use App\Game\Belote\BeloteState;
use App\Repository\BeloteGameRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une partie de belote en cours ou terminée. L'état complet (mains, pli…) est en JSON ;
 * le score et le vainqueur sont aussi en colonnes, pour l'historique et les statistiques.
 */
#[ORM\Entity(repositoryClass: BeloteGameRepository::class)]
#[ORM\Table(name: 'belote_game')]
class BeloteGame
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: GameRoom::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private GameRoom $room;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $state;

    /** Augmente à chaque coup : la page sait si elle est à jour. */
    #[ORM\Column]
    private int $version = 1;

    /**
     * Ce qui s'est passé au dernier coup (pour l'animer chez les autres joueurs).
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $lastEvents = [];

    #[ORM\Column]
    private int $team1Score = 0;

    #[ORM\Column]
    private int $team2Score = 0;

    /** 1 ou 2 quand la partie est terminée. */
    #[ORM\Column(nullable: true)]
    private ?int $winnerTeam = null;

    /** Début du tour en cours : sert à jouer à la place d'un joueur qui dépasse son temps. */
    #[ORM\Column(options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $turnStartedAt;

    public function __construct(GameRoom $room, BeloteState $state)
    {
        $this->room = $room;
        $this->state = $state->toArray();
        $this->turnStartedAt = new \DateTimeImmutable();
    }

    public function getTurnStartedAt(): \DateTimeImmutable
    {
        return $this->turnStartedAt;
    }

    /**
     * Secondes restantes pour le joueur dont c'est le tour (0 si le temps est écoulé).
     */
    public function secondsLeft(\DateTimeImmutable $now): float
    {
        $elapsed = (float) $now->format('U.u') - (float) $this->turnStartedAt->format('U.u');

        return max(0.0, $this->room->getTurnSeconds() - $elapsed);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoom(): GameRoom
    {
        return $this->room;
    }

    public function getState(): BeloteState
    {
        return BeloteState::fromArray($this->state);
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getLastEvents(): array
    {
        return $this->lastEvents;
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    public function save(BeloteState $state, array $events): void
    {
        $this->state = $state->toArray();
        $this->lastEvents = $events;
        ++$this->version;
        // Chaque enregistrement passe la main à un joueur humain (les robots jouent aussitôt) : son temps démarre
        $this->turnStartedAt = new \DateTimeImmutable();
        [$this->team1Score, $this->team2Score] = $state->scores;
        $this->winnerTeam = null === $state->winnerTeam ? null : $state->winnerTeam + 1;
    }

    public function getTeam1Score(): int
    {
        return $this->team1Score;
    }

    public function getTeam2Score(): int
    {
        return $this->team2Score;
    }

    public function getWinnerTeam(): ?int
    {
        return $this->winnerTeam;
    }
}
