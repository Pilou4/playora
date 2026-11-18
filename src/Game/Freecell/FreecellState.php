<?php

declare(strict_types=1);

namespace App\Game\Freecell;

use App\Game\CardPatience\CardPatienceState;

/**
 * Une grille de FreeCell : 8 colonnes (toutes les cartes visibles), 4 cellules libres, 4 familles.
 *
 * - columns : les cartes de chaque colonne (la dernière est celle du dessus) ;
 * - cells : les 4 cellules (null : vide) ;
 * - foundations : nombre de cartes rangées sur chaque famille (0 à 13).
 */
final class FreecellState extends CardPatienceState
{
    /** @var list<list<string>> */
    public array $columns = [[], [], [], [], [], [], [], []];
    /** @var list<?string> */
    public array $cells = [null, null, null, null];
    /** @var array<string, int> */
    public array $foundations = ['pique' => 0, 'coeur' => 0, 'carreau' => 0, 'trefle' => 0];

    public function sortedCards(): int
    {
        return array_sum($this->foundations);
    }

    public function cardCount(): int
    {
        return 52;
    }

    public function board(): array
    {
        return ['columns' => $this->columns, 'cells' => $this->cells, 'foundations' => $this->foundations];
    }

    public function restore(array $board): void
    {
        $this->columns = $board['columns'];
        $this->cells = $board['cells'];
        $this->foundations = $board['foundations'];
    }
}
