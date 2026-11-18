<?php

declare(strict_types=1);

namespace App\Game\Spider;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Spider (règles GameDuell : débutant, Spider, Pro). Chaque coup est vérifié ici.
 *
 * - Piles de cartes : une carte se pose sur une carte de la hauteur juste au-dessus, quelle que soit sa couleur
 *   (un 9 sur un 10) ; n'importe quelle carte va sur une pile vide. Un groupe se déplace d'un coup
 *   s'il se suit dans une même couleur (10, 9, 8 de pique).
 * - Familles : du Roi à l'As dans une même couleur, une famille complète part seule sur les piles de base.
 * - Talon : on choisit une des 5 piles talon ; ses 10 cartes vont, retournées, une sur chaque pile de cartes,
 *   qui doivent toutes contenir au moins une carte.
 * - Joker : une seule carte à la fois par pile du Joker (une au débutant et au Spider, deux au Pro).
 */
final class SpiderEngine
{
    public const int COLUMNS = 10;
    public const int STOCK_PILES = 5;

    /**
     * Donne d'après une graine : la même graine et le même niveau donnent exactement les mêmes cartes.
     * Les 4 premières piles reçoivent 6 cartes, les 6 autres 5 (la dernière visible) ; 5 piles talon de 10 cartes.
     */
    public function deal(int $seed, SpiderLevel $level): SpiderState
    {
        $state = new SpiderState($seed, $level);
        $cards = (new Randomizer(new Xoshiro256StarStar($seed)))->shuffleArray(SpiderCard::deck($level));
        $codes = array_map(static fn (SpiderCard $card): string => $card->code(), $cards);

        foreach (range(0, self::COLUMNS - 1) as $column) {
            $count = $column < 4 ? 6 : 5;

            foreach (range(1, $count) as $row) {
                $state->columns[$column][] = [array_shift($codes), $row === $count];
            }
        }

        $state->stock = array_chunk($codes, self::COLUMNS);

        return $state;
    }

    /**
     * Déplacer une carte (et celles posées dessus) vers une pile (« colonne-0 » à « colonne-9 ») ou une pile du Joker (« joker-0 », « joker-1 »).
     *
     * @throws SpiderException
     */
    public function move(SpiderState $state, string $code, string $target): void
    {
        $this->assertPlaying($state);
        $card = SpiderCard::fromCode($code);
        [$source, $block] = $this->locate($state, $code);

        if (1 === preg_match('/^colonne-(\d)$/', $target, $match)) {
            $column = (int) $match[1];

            if ('colonne' === $source[0] && $source[1] === $column) {
                throw new SpiderException('La carte est déjà sur cette pile.');
            }

            $this->assertFits($state->columns[$column], $card);
            $this->remember($state);
            $this->take($state, $source, \count($block));

            foreach ($block as $moved) {
                $state->columns[$column][] = [$moved, true];
            }
        } elseif (1 === preg_match('/^joker-(\d)$/', $target, $match) && (int) $match[1] < \count($state->jokers)) {
            $slot = (int) $match[1];

            if ('joker' === $source[0]) {
                throw new SpiderException('Cette carte est déjà sur une pile du Joker.');
            }

            if (\count($block) > 1) {
                throw new SpiderException('Une seule carte à la fois sur la pile du Joker.');
            }

            if (null !== $state->jokers[$slot]) {
                throw new SpiderException('Videz d’abord la pile du Joker.');
            }

            $this->remember($state);
            $this->take($state, $source, 1);
            $state->jokers[$slot] = $code;
            $state->jokerUsed = true;
        } else {
            throw new SpiderException('Destination inconnue.');
        }

        ++$state->moves;
        $this->settle($state);
    }

    /**
     * Clic sur une pile talon (0 à 4) : ses 10 cartes sont distribuées, une sur chaque pile de cartes.
     *
     * @throws SpiderException
     */
    public function dealStock(SpiderState $state, int $pile): void
    {
        $this->assertPlaying($state);

        if ([] === ($state->stock[$pile] ?? [])) {
            throw new SpiderException('Cette pile talon est vide.');
        }

        if (!$this->allColumnsFilled($state)) {
            throw new SpiderException('Pour distribuer, chaque pile doit contenir au moins une carte.');
        }

        $this->remember($state);

        foreach ($state->stock[$pile] as $column => $code) {
            $state->columns[$column][] = [$code, true];
        }

        $state->stock[$pile] = [];
        ++$state->moves;
        $this->settle($state);
    }

    /**
     * Bouton « Annuler » : la position d'avant le dernier coup, score compris, moins le coût de l'annulation.
     * Sans assez de points, on ne peut plus annuler.
     *
     * @throws SpiderException
     */
    public function undo(SpiderState $state): void
    {
        $this->assertPlaying($state);
        $cost = $state->level->undoCost();

        if ([] === $state->history) {
            throw new SpiderException('Rien à annuler.');
        }

        if ($state->score < $cost) {
            throw new SpiderException(\sprintf('Il faut au moins %d points pour annuler.', $cost));
        }

        $state->restore(array_pop($state->history));
        $state->score -= $cost;
        ++$state->undos;
    }

    /**
     * Fin de la grille (tout rangé, « Arrêter » ou temps écoulé). Tout rangé : points de fin, bonus « sans Joker »
     * et bonus de temps par seconde restante. Renvoie les points ajoutés.
     */
    public function finish(SpiderState $state, int $secondsLeft, bool $complete): int
    {
        $bonus = 0;

        if ($complete) {
            $level = $state->level;
            $bonus = $level->finishPoints() + ($state->jokerUsed ? 0 : $level->noJokerPoints()) + max(0, $secondsLeft) * $level->pointsPerSecond();
        }

        $state->score += $bonus;
        $state->bonus = $bonus;
        $state->finished = true;
        $state->history = [];

        return $bonus;
    }

    /**
     * Où chaque carte peut aller : « colonne-N » et/ou « joker-K ». Sert à la page pour éclairer les places possibles ;
     * le coup lui-même est toujours revérifié par move().
     *
     * @return array<string, list<string>>
     */
    public function targets(SpiderState $state): array
    {
        if ($state->finished) {
            return [];
        }

        $targets = [];

        foreach ($state->columns as $index => $cards) {
            foreach ($cards as $row => [$code, $up]) {
                if ($up && $this->isRun(\array_slice($cards, $row))) {
                    $places = $this->placesFor($state, SpiderCard::fromCode($code), ['colonne', $index], \count($cards) - $row);

                    if ([] !== $places) {
                        $targets[$code] = $places;
                    }
                }
            }
        }

        foreach ($state->jokers as $slot => $code) {
            if (null !== $code) {
                $places = $this->placesFor($state, SpiderCard::fromCode($code), ['joker', $slot], 1);

                if ([] !== $places) {
                    $targets[$code] = $places;
                }
            }
        }

        return $targets;
    }

    /** On peut distribuer : il reste une pile talon et aucune pile de cartes n'est vide. */
    public function canDealStock(SpiderState $state): bool
    {
        return !$state->finished && $this->allColumnsFilled($state) && [] !== array_filter($state->stock);
    }

    // ---------- Interne ----------

    /**
     * Après chaque coup : les familles complètes partent sur les piles de base, les cartes du dessus se retournent,
     * les piles vidées pour la première fois rapportent des points.
     */
    private function settle(SpiderState $state): void
    {
        $level = $state->level;

        foreach ($state->columns as $index => $cards) {
            if (\count($cards) >= 13 && $this->isRun(\array_slice($cards, -13)) && 13 === SpiderCard::fromCode($cards[\count($cards) - 13][0])->rank) {
                $state->foundations[] = SpiderCard::fromCode($cards[\count($cards) - 1][0])->suit;
                array_splice($state->columns[$index], -13);
                $state->score += $level->familyPoints();
            }
        }

        foreach ($state->columns as $index => $cards) {
            $last = \count($cards) - 1;

            if ($last >= 0 && !$cards[$last][1]) {
                $state->columns[$index][$last][1] = true;
                $state->score += $level->revealPoints();
            }

            if ($last < 0 && !\in_array($index, $state->emptied, true)) {
                $state->emptied[] = $index;
                $state->score += $level->emptyColumnPoints();
            }
        }

        if ($state->isComplete()) {
            $state->finished = true;
        }
    }

    /**
     * Où est la carte, et le groupe qui part avec elle (qui doit se suivre dans une même couleur).
     *
     * @return array{array{string, int}, list<string>}
     *
     * @throws SpiderException
     */
    private function locate(SpiderState $state, string $code): array
    {
        foreach ($state->jokers as $slot => $held) {
            if ($held === $code) {
                return [['joker', $slot], [$code]];
            }
        }

        foreach ($state->columns as $index => $cards) {
            foreach ($cards as $row => [$held, $up]) {
                if ($held === $code) {
                    if (!$up) {
                        throw new SpiderException('Cette carte est encore face cachée.');
                    }

                    $block = \array_slice($cards, $row);

                    if (!$this->isRun($block)) {
                        throw new SpiderException('Un groupe ne se déplace que s’il se suit dans une même couleur.');
                    }

                    return [['colonne', $index], array_map(static fn (array $entry): string => $entry[0], $block)];
                }
            }
        }

        throw new SpiderException('Cette carte ne peut pas être prise.');
    }

    /**
     * @param array{string, int} $source
     *
     * @return list<string>
     */
    private function placesFor(SpiderState $state, SpiderCard $card, array $source, int $size): array
    {
        $places = [];

        foreach ($state->columns as $index => $cards) {
            if ('colonne' === $source[0] && $source[1] === $index) {
                continue;
            }

            if ($this->fits($cards, $card)) {
                $places[] = 'colonne-'.$index;
            }
        }

        if ('colonne' === $source[0] && 1 === $size) {
            foreach ($state->jokers as $slot => $held) {
                if (null === $held) {
                    $places[] = 'joker-'.$slot;
                }
            }
        }

        return $places;
    }

    /**
     * Des cartes visibles qui se suivent en descendant dans une même couleur (Roi, Dame, Valet… de pique).
     *
     * @param list<array{string, bool}> $cards
     */
    private function isRun(array $cards): bool
    {
        $previous = null;

        foreach ($cards as [$code, $up]) {
            $card = SpiderCard::fromCode($code);

            if (!$up || (null !== $previous && ($previous->suit !== $card->suit || $previous->rank !== $card->rank + 1))) {
                return false;
            }

            $previous = $card;
        }

        return true;
    }

    /**
     * @param list<array{string, bool}> $column
     */
    private function fits(array $column, SpiderCard $card): bool
    {
        if ([] === $column) {
            return true;
        }

        [$code, $up] = $column[\count($column) - 1];

        return $up && SpiderCard::fromCode($code)->rank === $card->rank + 1;
    }

    /**
     * @param list<array{string, bool}> $column
     *
     * @throws SpiderException
     */
    private function assertFits(array $column, SpiderCard $card): void
    {
        if (!$this->fits($column, $card)) {
            throw new SpiderException('Il faut une carte d’une hauteur juste au-dessus (un 9 sur un 10).');
        }
    }

    /**
     * @param array{string, int} $source
     */
    private function take(SpiderState $state, array $source, int $count): void
    {
        if ('joker' === $source[0]) {
            $state->jokers[$source[1]] = null;

            return;
        }

        array_splice($state->columns[$source[1]], -$count);
    }

    private function allColumnsFilled(SpiderState $state): bool
    {
        foreach ($state->columns as $cards) {
            if ([] === $cards) {
                return false;
            }
        }

        return true;
    }

    private function remember(SpiderState $state): void
    {
        $state->history[] = $state->board();

        if (\count($state->history) > SpiderState::MAX_UNDO) {
            array_shift($state->history);
        }
    }

    private function assertPlaying(SpiderState $state): void
    {
        if ($state->finished) {
            throw new SpiderException('La partie est terminée.');
        }
    }
}
