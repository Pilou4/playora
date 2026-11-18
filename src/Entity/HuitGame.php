<?php

declare(strict_types=1);

namespace App\Entity;

use App\Game\Huit\HuitState;
use App\Repository\HuitGameRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une partie de 8 américain en cours ou terminée. La manche en cours (mains, pioche, pile, couleur, sens) est en JSON ;
 * les points cumulés de chaque place et le vainqueur sont en colonnes, pour l'historique et les statistiques.
 */
#[ORM\Entity(repositoryClass: HuitGameRepository::class)]
#[ORM\Table(name: 'huit_game')]
class HuitGame
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

    /** Numéro de la manche en cours (à partir de 1). */
    #[ORM\Column]
    private int $round = 1;

    /** Place gagnante (à partir de 1) quand la partie est terminée. */
    #[ORM\Column(nullable: true)]
    private ?int $winnerPosition = null;

    #[ORM\Column]
    private \DateTimeImmutable $turnStartedAt;

    public function __construct(GameRoom $room, HuitState $state)
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
     * Enregistre la manche. Le temps repart quand le tour passe à un autre joueur (ou qu'une manche commence).
     */
    public function save(HuitState $state, bool $newTurn): void
    {
        $this->state = $state->toArray();
        ++$this->version;

        if ($newTurn) {
            $this->turnStartedAt = new \DateTimeImmutable();
        }
    }

    /**
     * Fin d'une manche : pénalités ajoutées, et nouvelle manche.
     *
     * @param list<int> $penalties
     */
    public function addPenalties(array $penalties): void
    {
        foreach ($penalties as $position => $points) {
            $this->scores[$position] += $points;
        }
    }

    public function nextRound(): void
    {
        ++$this->round;
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

    public function getState(): HuitState
    {
        return HuitState::fromArray($this->state);
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

    public function getRound(): int
    {
        return $this->round;
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
