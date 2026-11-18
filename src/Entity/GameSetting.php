<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CardBack;
use App\Enum\CardSet;
use App\Enum\Carpet;
use App\Enum\Game;
use App\Repository\GameSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réglages d'un jeu, gérés par l'admin : ils s'appliquent à toutes les nouvelles parties de ce jeu.
 * Sans ligne en base, le jeu garde les réglages par défaut (et il est actif).
 * Un jeu inactif n'apparaît pas dans la liste des jeux : seuls les admins peuvent y jouer, pour le tester.
 */
#[ORM\Entity(repositoryClass: GameSettingRepository::class)]
#[ORM\Table(name: 'game_setting')]
#[ORM\UniqueConstraint(name: 'uniq_game_setting_game', columns: ['game'])]
class GameSetting
{
    /** Durées proposées, en secondes (pour jouer une carte, et pour prendre ou passer). */
    public const array TURN_SECONDS_CHOICES = [10, 15, 20, 30];
    public const int RAMI_TURN_SECONDS = 30;
    public const array BID_SECONDS_CHOICES = [10, 15, 20, 30];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: Game::class)]
    private Game $game;

    #[ORM\Column(length: 20, enumType: Carpet::class)]
    private Carpet $carpet = Carpet::Lagon;

    #[ORM\Column(length: 20, enumType: CardSet::class)]
    private CardSet $cardSet = CardSet::Modern;

    #[ORM\Column(length: 20, enumType: CardBack::class, options: ['default' => 'moderne'])]
    private CardBack $cardBack = CardBack::Modern;

    #[ORM\Column]
    private int $turnSeconds = GameRoom::TURN_SECONDS;

    #[ORM\Column(options: ['default' => GameRoom::BID_SECONDS])]
    private int $bidSeconds = GameRoom::BID_SECONDS;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Game $game)
    {
        $this->game = $game;
        $this->cardSet = CardSet::defaultFor($game);
        // Rami : un tour compte plusieurs gestes (prendre, poser, défausser)
        $this->turnSeconds = $game->isRami() ? self::RAMI_TURN_SECONDS : GameRoom::TURN_SECONDS;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function update(Carpet $carpet, CardSet $cardSet, int $turnSeconds, int $bidSeconds, CardBack $cardBack = CardBack::Modern): void
    {
        if (!\in_array($turnSeconds, self::TURN_SECONDS_CHOICES, true)) {
            throw new \InvalidArgumentException('Durée de tour non autorisée.');
        }

        if (!\in_array($cardSet, CardSet::forGame($this->game), true)) {
            throw new \InvalidArgumentException('Ce jeu de cartes ne convient pas à ce jeu.');
        }

        if (!\in_array($bidSeconds, self::BID_SECONDS_CHOICES, true)) {
            throw new \InvalidArgumentException('Durée des enchères non autorisée.');
        }

        $this->bidSeconds = $bidSeconds;

        $this->carpet = $carpet;
        $this->cardSet = $cardSet;
        $this->cardBack = $cardBack;
        $this->turnSeconds = $turnSeconds;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGame(): Game
    {
        return $this->game;
    }

    public function getCarpet(): Carpet
    {
        return $this->carpet;
    }

    public function getCardSet(): CardSet
    {
        return $this->cardSet;
    }

    public function getCardBack(): CardBack
    {
        return $this->cardBack;
    }

    public function getTurnSeconds(): int
    {
        return $this->turnSeconds;
    }

    public function getBidSeconds(): int
    {
        return $this->bidSeconds;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
