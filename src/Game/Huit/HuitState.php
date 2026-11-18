<?php

declare(strict_types=1);

namespace App\Game\Huit;

/**
 * Une manche de 8 américain en cours, enregistrée en JSON. Les cartes sont des codes (« coeur-7-1 »).
 *
 * - hands : la main de chaque place (à partir de 0) ;
 * - stock : la pioche (la carte du dessus est la dernière) ; pile : les cartes jouées (idem) ;
 * - suit : la couleur à jouer (celle de la carte du dessus, ou celle choisie avec un 8) ;
 * - direction : 1 dans le sens des aiguilles d'une montre, -1 dans l'autre sens ;
 * - penalty : cartes à piocher par le joueur dont c'est le tour (As posés à la suite) ;
 * - drawn : la carte que le joueur vient de piocher (lui seul peut encore la poser, sinon il passe).
 */
final class HuitState
{
    public const string PLAY = 'jeu';
    public const string OVER = 'finie';

    /** @var list<list<string>> */
    public array $hands = [];
    /** @var list<string> */
    public array $stock = [];
    /** @var list<string> */
    public array $pile = [];
    public string $suit = 'coeur';
    public int $direction = 1;
    public int $turn = 0;
    public int $penalty = 0;
    public ?string $drawn = null;
    public string $phase = self::PLAY;
    public ?int $winner = null;
    /** @var list<int> pénalités de la manche, une fois finie */
    public array $penalties = [];

    public function __construct(
        public readonly int $players,
        public readonly int $dealer,
    ) {
        $this->hands = array_fill(0, $players, []);
    }

    public function isOver(): bool
    {
        return self::OVER === $this->phase;
    }

    public function top(): ?string
    {
        return [] === $this->pile ? null : $this->pile[\count($this->pile) - 1];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'players' => $this->players,
            'dealer' => $this->dealer,
            'hands' => $this->hands,
            'stock' => $this->stock,
            'pile' => $this->pile,
            'suit' => $this->suit,
            'direction' => $this->direction,
            'turn' => $this->turn,
            'penalty' => $this->penalty,
            'drawn' => $this->drawn,
            'phase' => $this->phase,
            'winner' => $this->winner,
            'penalties' => $this->penalties,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $state = new self($data['players'], $data['dealer']);

        foreach (['hands', 'stock', 'pile', 'suit', 'direction', 'turn', 'penalty', 'drawn', 'phase', 'winner', 'penalties'] as $field) {
            $state->{$field} = $data[$field];
        }

        return $state;
    }
}
