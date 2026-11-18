<?php

declare(strict_types=1);

namespace App\Entity;

use App\Game\Golf\GolfState;
use App\Repository\GolfGameRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une partie de Golf en cours ou terminée. Le trou en cours (grilles, cartes retournées, pioche, défausse) est en JSON ;
 * les points cumulés de chaque place et le vainqueur sont en colonnes, pour l'historique et les statistiques.
 */
#[ORM\Entity(repositoryClass: GolfGameRepository::class)]
#[ORM\Table(name: 'golf_game')]
class GolfGame
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

    /** Points cumulés de chaque place (2 à 6 joueurs). @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $scores;

    /** Numéro du trou en cours (à partir de 1). */
    #[ORM\Column]
    private int $hole = 1;

    /** Place gagnante (à partir de 1) quand la partie est terminée. */
    #[ORM\Column(nullable: true)]
    private ?int $winnerPosition = null;

    #[ORM\Column]
    private \DateTimeImmutable $turnStartedAt;

    public function __construct(GameRoom $room, GolfState $state)
    {
        $this->room = $room;
        $this->state = $state->toArray();
        $this->scores = array_fill(0, $state->players, 0);
        $this->turnStartedAt = new \DateTimeImmutable();
    }

    public function secondsLeft(\DateTimeImmutable $now): float
    {
        $elapsed = (float) $now->format('U.u') - (float) $this->turnStartedAt->format('U.u');

        return max(0.0, $this->room->getTurnSeconds() - $elapsed);
    }

    /**
     * Enregistre le trou. Le temps repart quand le tour passe à un autre joueur (ou qu'un trou commence).
     */
    public function save(GolfState $state, bool $newTurn): void
    {
        $this->state = $state->toArray();
        ++$this->version;

        if ($newTurn) {
            $this->turnStartedAt = new \DateTimeImmutable();
        }
    }

    /**
     * Fin d'un trou : les points de chacun s'ajoutent.
     *
     * @param list<int> $points
     */
    public function addPoints(array $points): void
    {
        foreach ($points as $position => $value) {
            $this->scores[$position] += $value;
        }
    }

    public function nextHole(): void
    {
        ++$this->hole;
    }

    /** Fin de la partie : null en cas d'égalité. */
    public function finish(?int $winnerPosition): void
    {
        $this->winnerPosition = $winnerPosition;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoom(): GameRoom
    {
        return $this->room;
    }

    public function getState(): GolfState
    {
        return GolfState::fromArray($this->state);
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * @return list<int>
     */
    public function getScores(): array
    {
        return $this->scores;
    }

    public function getHole(): int
    {
        return $this->hole;
    }

    public function getWinnerPosition(): ?int
    {
        return $this->winnerPosition;
    }

    public function getTurnStartedAt(): \DateTimeImmutable
    {
        return $this->turnStartedAt;
    }
}
