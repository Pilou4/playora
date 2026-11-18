<?php

declare(strict_types=1);

namespace App\Game\GolfSolitaire;

use App\Game\CardPatience\CardPatienceState;

/**
 * Une grille de Golf solitaire : 7 colonnes de 5 cartes visibles, le talon (16 cartes, face cachée) et la pile
 * (la dernière carte est celle sur laquelle on joue).
 */
final class GolfSolitaireState extends CardPatienceState
{
    /** @var list<list<string>> */
    public array $columns = [[], [], [], [], [], [], []];
    /** @var list<string> */
    public array $stock = [];
    /** @var list<string> */
    public array $pile = [];

    public function sortedCards(): int
    {
        return 35 - array_sum(array_map('count', $this->columns));
    }

    public function cardCount(): int
    {
        return 35;
    }

    public function board(): array
    {
        return ['columns' => $this->columns, 'stock' => $this->stock, 'pile' => $this->pile];
    }

    public function restore(array $board): void
    {
        $this->columns = $board['columns'];
        $this->stock = $board['stock'];
        $this->pile = $board['pile'];
    }
}
