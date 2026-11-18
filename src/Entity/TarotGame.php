<?php

declare(strict_types=1);

namespace App\Entity;

use App\Game\Tarot\TarotPhase;
use App\Game\Tarot\TarotState;
use App\Repository\TarotGameRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une partie de tarot en cours ou terminée. L'état complet (mains, chien, pli…) est en JSON ;
 * les scores de chaque place et le vainqueur sont aussi en colonnes, pour l'historique et les statistiques.
 */
#[ORM\Entity(repositoryClass: TarotGameRepository::class)]
#[ORM\Table(name: 'tarot_game')]
class TarotGame
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

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: Types::JSON)]
    private array $lastEvents = [];

    /** Score de chaque place (3 à 5 joueurs). @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $scores = [0, 0, 0, 0];

    /** Place gagnante (à partir de 1) quand la partie est terminée. */
    #[ORM\Column(nullable: true)]
    private ?int $winnerPosition = null;

    #[ORM\Column]
    private \DateTimeImmutable $turnStartedAt;

    public function __construct(GameRoom $room, TarotState $state)
    {
        $this->room = $room;
        $this->state = $state->toArray();
        $this->turnStartedAt = new \DateTimeImmutable();
    }

    public function secondsLeft(\DateTimeImmutable $now): float
    {
        $elapsed = (float) $now->format('U.u') - (float) $this->turnStartedAt->format('U.u');

        return max(0.0, $this->duration() - $elapsed);
    }

    /** Temps accordé : enchères avec la durée des enchères ; écart et jeu avec la durée d'un tour (l'écart a le double). */
    public function duration(): int
    {
        return match ($this->getState()->phase) {
            TarotPhase::Bidding, TarotPhase::Call => $this->room->getBidSeconds(),
            TarotPhase::Dog => 2 * $this->room->getTurnSeconds(),
            default => $this->room->getTurnSeconds(),
        };
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    public function save(TarotState $state, array $events, bool $keepClock = false): void
    {
        $this->state = $state->toArray();
        $this->lastEvents = $events;
        ++$this->version;
        $this->scores = $state->scores;
        $this->winnerPosition = null === $state->winner ? null : $state->winner + 1;

        // Le joueur qui garde la main (poignée, chelem annoncé) garde aussi son temps
        if (!$keepClock) {
            $this->turnStartedAt = new \DateTimeImmutable();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoom(): GameRoom
    {
        return $this->room;
    }

    public function getState(): TarotState
    {
        return TarotState::fromArray($this->state);
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
     * @return list<int>
     */
    public function getScores(): array
    {
        return $this->scores;
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
