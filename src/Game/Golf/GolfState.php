<?php

declare(strict_types=1);

namespace App\Game\Golf;

/**
 * Un trou de Golf en cours, enregistré en JSON. Les cartes sont des codes (« coeur-7-1 »).
 *
 * - grids : les 6 cartes de chaque place (à partir de 0), en 2 lignes de 3 : 0 1 2 en haut, 3 4 5 en bas ;
 *   une colonne, ce sont les cartes i et i + 3 ;
 * - up : pour chaque carte de chaque grille, si elle est retournée (visible de tous) ;
 * - stock : la pioche (la carte du dessus est la dernière) ; discard : la défausse (idem) ;
 * - phase « debut » : chacun retourne 2 cartes de son choix ; « jeu » : chacun son tour ; « finie » : trou terminé ;
 * - drawn : la carte que le joueur vient de piocher (à échanger, ou à jeter en retournant une carte) ;
 * - closer : le premier joueur à avoir retourné ses 6 cartes (les autres jouent encore une fois) ;
 * - points : les points de chacun sur ce trou, une fois fini.
 */
final class GolfState
{
    public const string START = 'debut';
    public const string PLAY = 'jeu';
    public const string OVER = 'finie';
    public const int SIZE = 6;
    public const int COLUMNS = 3;

    /** @var list<list<string>> */
    public array $grids = [];
    /** @var list<list<bool>> */
    public array $up = [];
    /** @var list<string> */
    public array $stock = [];
    /** @var list<string> */
    public array $discard = [];
    public int $turn = 0;
    public ?string $drawn = null;
    public string $phase = self::START;
    public ?int $closer = null;
    /** @var list<int> */
    public array $points = [];

    public function __construct(
        public readonly int $players,
        public readonly int $dealer,
    ) {
        $this->grids = array_fill(0, $players, []);
        $this->up = array_fill(0, $players, array_fill(0, self::SIZE, false));
    }

    public function isOver(): bool
    {
        return self::OVER === $this->phase;
    }

    public function top(): ?string
    {
        return [] === $this->discard ? null : $this->discard[\count($this->discard) - 1];
    }

    /** Nombre de cartes retournées par ce joueur. */
    public function upCount(int $position): int
    {
        return \count(array_filter($this->up[$position]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'players' => $this->players,
            'dealer' => $this->dealer,
            'grids' => $this->grids,
            'up' => $this->up,
            'stock' => $this->stock,
            'discard' => $this->discard,
            'turn' => $this->turn,
            'drawn' => $this->drawn,
            'phase' => $this->phase,
            'closer' => $this->closer,
            'points' => $this->points,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $state = new self($data['players'], $data['dealer']);

        foreach (['grids', 'up', 'stock', 'discard', 'turn', 'drawn', 'phase', 'closer', 'points'] as $field) {
            $state->{$field} = $data[$field];
        }

        return $state;
    }
}
