<?php

declare(strict_types=1);

namespace App\Entity;

use App\Game\Patience\PatienceState;
use App\Repository\PatienceBoardRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * La grille d'un joueur pour une manche : l'état complet en JSON (cartes, historique du retour, selon le jeu),
 * le score et l'heure de fin en colonnes. Chaque joueur n'écrit que sa grille : tous jouent en même temps sans s'attendre.
 * La table garde son nom d'origine (solitaire_board) : aucune migration.
 */
#[ORM\Entity(repositoryClass: PatienceBoardRepository::class)]
#[ORM\Table(name: 'solitaire_board')]
#[ORM\UniqueConstraint(name: 'solitaire_board_unique', columns: ['game_id', 'position', 'round'])]
class PatienceBoard
{
    /** Temps d'une manche, pour tous les jeux de patience : 5 minutes. */
    public const int SECONDS = 300;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PatienceGame::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PatienceGame $game;

    /** Place du joueur, à partir de 0. */
    #[ORM\Column]
    private int $position;

    #[ORM\Column]
    private int $round;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $state;

    #[ORM\Column]
    private int $score = 0;

    /** Toutes les cartes rangées. */
    #[ORM\Column]
    private bool $complete = false;

    /** Début du temps de la manche (quelques secondes après sa création, le temps d'annoncer la manche). */
    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(PatienceGame $game, int $position, int $round, PatienceState $state, \DateTimeImmutable $startedAt)
    {
        $this->game = $game;
        $this->position = $position;
        $this->round = $round;
        $this->state = $state->toArray();
        $this->startedAt = $startedAt;
    }

    public function save(PatienceState $state): void
    {
        $this->state = $state->toArray();
        $this->score = $state->score();
        $this->complete = $state->isComplete();

        if ($state->isFinished() && null === $this->finishedAt) {
            $this->finishedAt = new \DateTimeImmutable();
        }
    }

    /** Secondes avant le début de la manche (0 quand elle a commencé). */
    public function secondsBeforeStart(\DateTimeImmutable $now): float
    {
        return max(0.0, (float) $this->startedAt->format('U.u') - (float) $now->format('U.u'));
    }

    /** Temps qui reste (5 minutes au départ ; tout le temps tant que la manche n'a pas commencé). */
    public function secondsLeft(\DateTimeImmutable $now): float
    {
        $elapsed = (float) $now->format('U.u') - (float) $this->startedAt->format('U.u');

        return min((float) self::SECONDS, max(0.0, self::SECONDS - $elapsed));
    }

    /** Temps joué, en secondes (null tant que la grille n'est pas terminée). */
    public function secondsPlayed(): ?int
    {
        if (null === $this->finishedAt) {
            return null;
        }

        return max(0, min(self::SECONDS, $this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp()));
    }

    public function isFinished(): bool
    {
        return null !== $this->finishedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGame(): PatienceGame
    {
        return $this->game;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getRound(): int
    {
        return $this->round;
    }

    /**
     * L'état enregistré, à relire avec les règles du jeu (PatienceRules::restore).
     *
     * @return array<string, mixed>
     */
    public function getStateData(): array
    {
        return $this->state;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }
}
