<?php

declare(strict_types=1);

namespace App\Game\Freecell;

use App\Game\CardPatience\CardPatienceException;
use App\Game\CardPatience\CardPatienceRules;
use App\Game\CardPatience\CardPatienceState;
use App\Game\Patience\PatienceState;
use App\Game\Solitaire\SolitaireCard;

/**
 * FreeCell : les 52 cartes visibles sur 8 colonnes (7, 7, 7, 7, 6, 6, 6, 6). On descend en alternant les couleurs ;
 * une cellule libre garde une carte de côté ; on range chaque famille de l'As au Roi.
 * Plusieurs cartes à la fois : autant que le permettent les cellules et les colonnes vides ((cellules + 1) × 2^colonnes vides).
 * Places : « colonne-0 » à « colonne-7 », « cellule-0 » à « cellule-3 », « famille ».
 */
final class FreecellRules extends CardPatienceRules
{
    protected function newState(int $seed, array $cards): FreecellState
    {
        $state = new FreecellState($seed);

        foreach ($cards as $index => $card) {
            $state->columns[$index % 8][] = $card;
        }

        return $state;
    }

    public function restore(array $data): FreecellState
    {
        return FreecellState::fromArray($data);
    }

    /** Rangement automatique : les cartes dont personne n'a plus besoin montent sur leur famille. */
    public function afterMove(PatienceState $state, bool $auto): void
    {
        $state = $this->freecell($state);

        if (!$auto || $state->isFinished() || $state->isComplete()) {
            return;
        }

        do {
            $moved = false;

            foreach ($this->tops($state) as $card) {
                $parsed = SolitaireCard::fromCode($card);

                if ($this->fitsFoundation($state, $parsed) && $this->isSafe($state, $parsed)) {
                    $this->take($state, $card);
                    ++$state->foundations[$parsed->suit];
                    $this->scoreCard($state);
                    $moved = true;
                }
            }
        } while ($moved && !$state->isComplete());

        if ($state->isComplete()) {
            $state->score += self::COMPLETE;
        }
    }

    protected function play(CardPatienceState $state, string $card, string $to): void
    {
        $state = $this->freecell($state);
        $parsed = SolitaireCard::fromCode($card);
        [$where, $index, $count] = $this->locate($state, $card);

        if ('famille' === $to) {
            if (1 !== $count || !$this->fitsFoundation($state, $parsed)) {
                throw new CardPatienceException('Cette carte ne va pas encore sur sa famille.');
            }

            $this->take($state, $card);
            ++$state->foundations[$parsed->suit];
            $this->scoreCard($state);

            return;
        }

        if (preg_match('/^cellule-([0-3])$/', $to, $match)) {
            if (1 !== $count) {
                throw new CardPatienceException('Une cellule ne garde qu’une carte.');
            }

            if (null !== $state->cells[(int) $match[1]]) {
                throw new CardPatienceException('Cette cellule est occupée.');
            }

            $this->take($state, $card);
            $state->cells[(int) $match[1]] = $card;

            return;
        }

        if (!preg_match('/^colonne-([0-7])$/', $to, $match)) {
            throw new CardPatienceException('Place inconnue.');
        }

        $target = (int) $match[1];

        if ('colonne' === $where && $index === $target) {
            throw new CardPatienceException('La carte est déjà dans cette colonne.');
        }

        $column = $state->columns[$target];

        if ([] !== $column && !$this->fitsOn(SolitaireCard::fromCode($column[array_key_last($column)]), $parsed)) {
            throw new CardPatienceException('Il faut une carte d’une hauteur de moins et de l’autre couleur.');
        }

        if ($count > $this->maxMove($state, [] === $column)) {
            throw new CardPatienceException('Pas assez de cellules libres pour déplacer toutes ces cartes.');
        }

        $moving = 'colonne' === $where ? \array_slice($state->columns[$index], -$count) : [$card];
        $this->take($state, $card, $count);
        array_push($state->columns[$target], ...$moving);
    }

    public function targets(CardPatienceState $state): array
    {
        $state = $this->freecell($state);
        $targets = [];

        foreach ($this->movable($state) as $card => $count) {
            $parsed = SolitaireCard::fromCode($card);
            $places = [];

            if (1 === $count && $this->fitsFoundation($state, $parsed)) {
                $places[] = 'famille';
            }

            foreach ($state->columns as $index => $column) {
                if (\in_array($card, $column, true)) {
                    continue;
                }

                $fits = [] === $column ? $count <= $this->maxMove($state, true) : $count <= $this->maxMove($state, false) && $this->fitsOn(SolitaireCard::fromCode($column[array_key_last($column)]), $parsed);

                if ($fits) {
                    $places[] = 'colonne-'.$index;
                }
            }

            if (1 === $count && !\in_array($card, $state->cells, true)) {
                foreach ($state->cells as $index => $cell) {
                    if (null === $cell) {
                        $places[] = 'cellule-'.$index;
                        break;
                    }
                }
            }

            if ([] !== $places) {
                $targets[$card] = $places;
            }
        }

        return $targets;
    }

    public function cards(CardPatienceState $state): array
    {
        $state = $this->freecell($state);

        return ['columns' => $state->columns, 'cells' => $state->cells, 'foundations' => $state->foundations];
    }

    /**
     * Les cartes qu'on peut prendre (avec le nombre de cartes qui partent avec elles) : le haut de chaque suite
     * bien rangée d'une colonne, et les cartes des cellules.
     *
     * @return array<string, int>
     */
    private function movable(FreecellState $state): array
    {
        $movable = [];

        foreach ($state->columns as $column) {
            $count = \count($column);

            for ($row = $count - 1; $row >= 0; --$row) {
                $movable[$column[$row]] = $count - $row;

                if ($row > 0 && !$this->fitsOn(SolitaireCard::fromCode($column[$row - 1]), SolitaireCard::fromCode($column[$row]))) {
                    break;
                }
            }
        }

        foreach ($state->cells as $cell) {
            if (null !== $cell) {
                $movable[$cell] = 1;
            }
        }

        return $movable;
    }

    /**
     * Où est la carte et combien de cartes partent avec elle.
     *
     * @return array{string, int, int}
     *
     * @throws CardPatienceException
     */
    private function locate(FreecellState $state, string $card): array
    {
        $count = $this->movable($state)[$card] ?? throw new CardPatienceException('Cette carte ne peut pas être prise.');

        foreach ($state->columns as $index => $column) {
            if (\in_array($card, $column, true)) {
                return ['colonne', $index, $count];
            }
        }

        return ['cellule', (int) array_search($card, $state->cells, true), 1];
    }

    /** La carte (et celles posées dessus) quitte sa colonne ou sa cellule. */
    private function take(FreecellState $state, string $card, int $count = 1): void
    {
        foreach ($state->columns as $index => $column) {
            if (\in_array($card, $column, true)) {
                $state->columns[$index] = \array_slice($column, 0, -$count);

                return;
            }
        }

        $state->cells[(int) array_search($card, $state->cells, true)] = null;
    }

    /**
     * Les cartes du dessus (colonnes et cellules).
     *
     * @return list<string>
     */
    private function tops(FreecellState $state): array
    {
        $tops = array_filter($state->cells);

        foreach ($state->columns as $column) {
            if ([] !== $column) {
                $tops[] = $column[array_key_last($column)];
            }
        }

        return array_values($tops);
    }

    private function maxMove(FreecellState $state, bool $toEmptyColumn): int
    {
        $free = \count(array_filter($state->cells, static fn (?string $cell): bool => null === $cell));
        $empty = \count(array_filter($state->columns, static fn (array $column): bool => [] === $column)) - ($toEmptyColumn ? 1 : 0);

        return ($free + 1) * 2 ** max(0, $empty);
    }

    private function fitsOn(SolitaireCard $below, SolitaireCard $card): bool
    {
        return $below->rank === $card->rank + 1 && $below->isRed() !== $card->isRed();
    }

    private function fitsFoundation(FreecellState $state, SolitaireCard $card): bool
    {
        return $state->foundations[$card->suit] === $card->rank - 1;
    }

    /** Plus utile ailleurs : As, 2, ou les familles de l'autre couleur sont assez montées. */
    private function isSafe(FreecellState $state, SolitaireCard $card): bool
    {
        if ($card->rank <= 2) {
            return true;
        }

        $others = $card->isRed() ? [$state->foundations['pique'], $state->foundations['trefle']] : [$state->foundations['coeur'], $state->foundations['carreau']];

        return min($others) >= $card->rank - 1;
    }

    private function freecell(PatienceState $state): FreecellState
    {
        \assert($state instanceof FreecellState);

        return $state;
    }
}
