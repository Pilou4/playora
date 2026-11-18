<?php

declare(strict_types=1);

namespace App\Game\GolfSolitaire;

use App\Game\CardPatience\CardPatienceException;
use App\Game\CardPatience\CardPatienceRules;
use App\Game\CardPatience\CardPatienceState;
use App\Game\Patience\PatienceState;
use App\Game\Solitaire\SolitaireCard;

/**
 * Golf solitaire : la carte du dessus d'une colonne va sur la pile si elle a une hauteur de plus ou de moins
 * (couleur sans importance, pas de passage du Roi à l'As). Plus rien à jouer : on retourne une carte du talon sur la pile.
 * Le but : vider les 7 colonnes. 1 000 points par carte, 10 000 si tout part.
 * Coup : {"card": "coeur-7", "to": "pile"}.
 */
final class GolfSolitaireRules extends CardPatienceRules
{
    protected function newState(int $seed, array $cards): GolfSolitaireState
    {
        $state = new GolfSolitaireState($seed);

        foreach (range(0, 34) as $index) {
            $state->columns[$index % 7][] = $cards[$index];
        }

        $state->pile = [$cards[35]];
        $state->stock = \array_slice($cards, 36);

        return $state;
    }

    public function restore(array $data): GolfSolitaireState
    {
        return GolfSolitaireState::fromArray($data);
    }

    protected function play(CardPatienceState $state, string $card, string $to): void
    {
        $state = $this->golf($state);

        if ('pile' !== $to) {
            throw new CardPatienceException('Les cartes vont sur la pile.');
        }

        $column = $this->columnOf($state, $card) ?? throw new CardPatienceException('Seule la carte du dessus d’une colonne se joue.');

        if (!$this->fits($state, $card)) {
            throw new CardPatienceException('Il faut une hauteur de plus ou de moins que la carte de la pile.');
        }

        array_pop($state->columns[$column]);
        $state->pile[] = $card;
        $this->scoreCard($state);
    }

    protected function turnStock(CardPatienceState $state): void
    {
        $state = $this->golf($state);
        $state->pile[] = array_pop($state->stock);
    }

    public function canTurnStock(CardPatienceState $state): bool
    {
        return [] !== $this->golf($state)->stock;
    }

    public function targets(CardPatienceState $state): array
    {
        $state = $this->golf($state);
        $targets = [];

        foreach ($state->columns as $column) {
            $top = [] === $column ? null : $column[array_key_last($column)];

            if (null !== $top && $this->fits($state, $top)) {
                $targets[$top] = ['pile'];
            }
        }

        return $targets;
    }

    public function cards(CardPatienceState $state): array
    {
        $state = $this->golf($state);

        return [
            'columns' => $state->columns,
            'pile' => $state->pile[array_key_last($state->pile)],
            'stock' => \count($state->stock),
            'canDraw' => $this->canTurnStock($state),
        ];
    }

    private function fits(GolfSolitaireState $state, string $card): bool
    {
        $pile = SolitaireCard::fromCode($state->pile[array_key_last($state->pile)]);

        return 1 === abs(SolitaireCard::fromCode($card)->rank - $pile->rank);
    }

    private function columnOf(GolfSolitaireState $state, string $card): ?int
    {
        foreach ($state->columns as $index => $column) {
            if ([] !== $column && $column[array_key_last($column)] === $card) {
                return $index;
            }
        }

        return null;
    }

    private function golf(PatienceState $state): GolfSolitaireState
    {
        \assert($state instanceof GolfSolitaireState);

        return $state;
    }
}
