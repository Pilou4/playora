<?php

declare(strict_types=1);

namespace App\Game\Solitaire;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Solitaire, variante Klondike : 3 cartes retournées à la fois (Solitaire) ou une seule (Solitaire harmonie).
 * Chaque coup est vérifié ici.
 *
 * - Piles de tri : on pose une carte sur une carte de couleur opposée et de hauteur juste supérieure
 *   (un 9 rouge sur un 10 noir) ; seul un Roi (ou un bloc commençant par un Roi) va sur une pile vide ;
 *   un bloc de cartes visibles se déplace d'un coup.
 * - Familles : chacune commence par l'As, puis 2, 3… jusqu'au Roi. La dernière carte d'une famille
 *   peut revenir sur une pile de tri (sans point en plus).
 * - Talon : on retourne 3 cartes à la fois (ou une), seule la dernière se joue ; vide, on remet les cartes retournées en place.
 * - Rangement automatique : les As et les 2 montent seuls sur leur famille ; un 3 quand les quatre 2 sont rangés,
 *   un 4 quand les quatre 3 le sont, et ainsi de suite.
 */
final class SolitaireEngine
{
    /**
     * Donne d'après une graine : la même graine donne exactement les mêmes cartes, dans le même ordre.
     */
    public function deal(int $seed, int $draw = 1): SolitaireState
    {
        $state = new SolitaireState($seed);
        $state->draw = $draw;
        $cards = (new Randomizer(new Xoshiro256StarStar($seed)))->shuffleArray(SolitaireCard::deck());
        $codes = array_map(static fn (SolitaireCard $card): string => $card->code(), $cards);

        foreach (range(0, 6) as $column) {
            foreach (range(0, $column) as $row) {
                $state->tableau[$column][] = [array_shift($codes), $row === $column];
            }
        }

        $state->stock = $codes;

        return $state;
    }

    /**
     * Clic sur le talon : 3 cartes (ou une) sont retournées ; talon vide : les cartes retournées y retournent.
     *
     * @throws SolitaireException
     */
    public function draw(SolitaireState $state): void
    {
        $this->assertPlaying($state);

        if ([] === $state->stock && [] === $state->waste) {
            throw new SolitaireException('Le talon est vide.');
        }

        if ($state->lastCards()) {
            throw new SolitaireException('Plus rien à piocher : les dernières cartes du talon sont toutes visibles.');
        }

        // Comme le solitaire des ordinateurs : la pioche ne s'annule pas, et on ne revient plus sur le coup d'avant
        $state->history = [];

        if ([] !== $state->stock) {
            $state->fan = 0;

            for ($i = 0; $i < $state->draw && [] !== $state->stock; ++$i) {
                $state->waste[] = array_pop($state->stock);
            }

            // Comme le vrai jeu : toujours 3 cartes étalées après un clic ; s'il en restait moins au talon,
            // les cartes retournées juste avant complètent l'éventail
            $state->fan = min($state->draw, \count($state->waste));
            $this->showLastCards($state);
        } else {
            $state->stock = array_reverse($state->waste);
            $state->waste = [];
            $state->fan = 0;
            ++$state->passes;

            // À partir de la 4e consultation du talon, chaque nouvelle consultation coûte des points
            if ($state->passes >= SolitaireScore::FREE_PASSES) {
                // Jamais en dessous de zéro
                $state->score = max(0, $state->score + SolitaireScore::STOCK_PASS);
            }
        }

        ++$state->moves;
    }

    /**
     * Déplacer une carte (et celles posées dessus) vers une famille (« famille ») ou une pile de tri (« colonne-0 » à « colonne-6 »).
     *
     * @throws SolitaireException
     */
    public function move(SolitaireState $state, string $code, string $target): void
    {
        $this->assertPlaying($state);
        $card = SolitaireCard::fromCode($code);
        [$source, $block] = $this->locate($state, $code);

        if ('famille' === $target) {
            if (\count($block) > 1) {
                throw new SolitaireException('Une seule carte à la fois sur une famille.');
            }

            if ($state->foundations[$card->suit] !== $card->rank - 1) {
                throw new SolitaireException(1 === $card->rank ? 'Cette famille a déjà son As.' : 'Une famille se range dans l’ordre, de l’As au Roi.');
            }

            if ('famille' === $source[0]) {
                throw new SolitaireException('Cette carte est déjà rangée.');
            }

            $this->remember($state);
            $this->take($state, $source, \count($block));
            ++$state->foundations[$card->suit];
            // Chaque carte sur sa famille compte ; reprise sur une pile, elle reperd ses points (et les regagne en remontant)
            $state->score += SolitaireScore::FOUNDATION;
        } elseif (1 === preg_match('/^colonne-([0-6])$/', $target, $match)) {
            $column = (int) $match[1];

            if ('colonne' === $source[0] && $source[1] === $column) {
                throw new SolitaireException('La carte est déjà sur cette pile.');
            }

            $this->assertFits($state->tableau[$column], $card);
            $this->remember($state);
            $this->take($state, $source, \count($block));

            // Carte reprise sur sa famille : elle ne compte plus
            if ('famille' === $source[0]) {
                $state->score = max(0, $state->score - SolitaireScore::FOUNDATION);
            }

            foreach ($block as $moved) {
                $state->tableau[$column][] = [$moved, true];
            }
        } else {
            throw new SolitaireException('Destination inconnue.');
        }

        ++$state->moves;
        $this->revealAndFinish($state);
    }

    /**
     * Double-clic : la carte va directement sur sa famille si c'est possible.
     *
     * @throws SolitaireException
     */
    public function toFoundation(SolitaireState $state, string $code): void
    {
        $this->move($state, $code, 'famille');
    }

    /**
     * Rangement automatique : range sur les familles les cartes qui ne servent plus : les As et les 2 toujours,
     * puis un 3 seulement quand les quatre 2 sont rangés, un 4 quand les quatre 3 le sont… Renvoie les cartes rangées.
     * Seulement les cartes que le joueur voyait avant ce rangement : une carte retournée pendant le rangement (dans une
     * pile) ou restée sous l'éventail des cartes retournées ne part pas toute seule.
     *
     * @return list<string>
     */
    public function autoPlay(SolitaireState $state): array
    {
        $moved = [];
        $visible = $this->visibleCodes($state);

        do {
            $found = null;

            foreach ($this->playableCodes($state) as $code) {
                $card = SolitaireCard::fromCode($code);

                if (\in_array($code, $visible, true) && $state->foundations[$card->suit] === $card->rank - 1 && ($card->rank <= 2 || min($state->foundations) >= $card->rank - 1)) {
                    $found = $code;
                    break;
                }
            }

            if (null !== $found && !$state->finished) {
                $moved[] = $this->autoMove($state, $found);
            }
        } while (null !== $found && !$state->finished);

        return $moved;
    }

    /**
     * Fin de partie, comme le solitaire des ordinateurs : talon vide et toutes les cartes des piles visibles,
     * les cartes (des piles et des cartes retournées) montent toutes seules sur leurs familles, la plus petite d'abord.
     * Seulement si toutes peuvent monter ainsi jusqu'à la dernière ; sinon rien ne bouge. Renvoie les cartes rangées.
     *
     * @return list<string>
     */
    public function autoComplete(SolitaireState $state): array
    {
        $plan = $this->canFinishAlone($state) ? $this->completionPlan($state) : null;

        foreach ($plan ?? [] as $code) {
            $this->autoMove($state, $code);
        }

        return $plan ?? [];
    }

    /**
     * L'ordre des cartes qui finissent la partie, essayé sur une copie de la grille ; null si l'on reste bloqué.
     *
     * @return list<string>|null
     */
    private function completionPlan(SolitaireState $state): ?array
    {
        $copy = SolitaireState::fromArray($state->toArray());
        $plan = [];

        while (52 !== $copy->sorted()) {
            $tops = array_map(static fn (array $column): ?string => [] === $column ? null : $column[\count($column) - 1][0], $copy->tableau);
            $tops[] = [] === $copy->waste ? null : $copy->waste[\count($copy->waste) - 1];
            $ready = array_values(array_filter($tops, static fn (?string $code): bool => null !== $code
                && $copy->foundations[SolitaireCard::fromCode($code)->suit] === SolitaireCard::fromCode($code)->rank - 1));

            if ([] === $ready) {
                return null;
            }

            usort($ready, static fn (string $a, string $b): int => SolitaireCard::fromCode($a)->rank <=> SolitaireCard::fromCode($b)->rank);
            $this->move($copy, $ready[0], 'famille');
            $plan[] = $ready[0];
        }

        return $plan;
    }

    /** Une carte monte seule sur sa famille (notée pour que la page la montre monter). */
    private function autoMove(SolitaireState $state, string $code): string
    {
        [$source] = $this->locate($state, $code);
        // Le retour annule le coup du joueur avec les rangements qu'il a entraînés : on garde sa position d'avant
        $kept = $state->history;
        $this->move($state, $code, 'famille');
        $state->history = $kept;
        $state->auto[] = [$code, 'talon' === $source[0] ? 'talon' : 'colonne-'.$source[1]];
        --$state->moves;

        return $code;
    }

    /**
     * Les cartes que le joueur voit : l'éventail des cartes retournées et les cartes visibles des piles.
     *
     * @return list<string>
     */
    private function visibleCodes(SolitaireState $state): array
    {
        $codes = $state->visibleWaste();

        foreach ($state->tableau as $column) {
            foreach ($column as [$code, $up]) {
                if ($up) {
                    $codes[] = $code;
                }
            }
        }

        return $codes;
    }

    /**
     * Bouton « retour » : la position d'avant le dernier déplacement de carte, contre 1 000 points.
     * Un seul retour à la fois (on ne remonte pas plusieurs coups), et jamais après un clic sur la pioche.
     *
     * @throws SolitaireException
     */
    public function undo(SolitaireState $state): void
    {
        $this->assertPlaying($state);
        $board = $state->history[0] ?? throw new SolitaireException('Le retour annule seulement le dernier déplacement de carte.');

        if (!$this->canUndo($state)) {
            throw new SolitaireException('Pas assez de points pour revenir en arrière (il en faut 1 000).');
        }

        $state->history = [];
        $state->restore($board);
        // Le score d'avant ce déplacement (ses points annulés avec lui), moins le prix du retour
        $state->score = ($board['score'] ?? $state->score) + SolitaireScore::UNDO;
        ++$state->undos;
    }

    /**
     * « Retour » possible : un déplacement à annuler, et assez de points pour le payer (un score n'est jamais négatif).
     */
    public function canUndo(SolitaireState $state): bool
    {
        return !$state->finished && [] !== $state->history && ($state->history[0]['score'] ?? $state->score) + SolitaireScore::UNDO >= 0;
    }

    /**
     * Fin de la partie : bonus temps pour chaque seconde qui reste (tout rangé : 150 points par seconde ;
     * « Arrêter » ou « Quitter » avant : 1 point par seconde, pour départager les scores égaux), puis la partie est close.
     */
    public function finish(SolitaireState $state, int $secondsLeft, bool $complete): int
    {
        $secondsLeft = max(0, $secondsLeft);
        $bonus = $complete
            ? $secondsLeft * SolitaireScore::TIME_BONUS_PER_SECOND
            : $secondsLeft * SolitaireScore::STOP_BONUS_PER_SECOND;
        $state->score += $bonus;
        $state->bonus = $bonus;
        $state->finished = true;
        $state->history = [];

        return $bonus;
    }

    /**
     * Cartes qu'on peut prendre : le dessus des cartes retournées, des familles, et les cartes visibles des piles.
     *
     * @return list<string>
     */
    public function playableCodes(SolitaireState $state): array
    {
        $codes = [];

        if ([] !== $state->waste) {
            $codes[] = $state->waste[\count($state->waste) - 1];
        }

        foreach ($state->tableau as $column) {
            foreach ($column as [$code, $up]) {
                if ($up) {
                    $codes[] = $code;
                }
            }
        }

        return $codes;
    }

    /**
     * Où chaque carte peut aller : « famille » et/ou « colonne-N ». Sert à la page pour éclairer les places possibles ;
     * le coup lui-même est toujours revérifié par move().
     *
     * @return array<string, list<string>>
     */
    public function targets(SolitaireState $state): array
    {
        if ($state->finished) {
            return [];
        }

        $codes = $this->playableCodes($state);

        // La dernière carte d'une famille peut revenir sur une pile de tri
        foreach ($state->foundations as $suit => $count) {
            if ($count > 0) {
                $codes[] = (new SolitaireCard($suit, $count))->code();
            }
        }

        $targets = [];

        foreach ($codes as $code) {
            $card = SolitaireCard::fromCode($code);
            [$source, $block] = $this->locate($state, $code);
            $places = [];

            if ('famille' !== $source[0] && 1 === \count($block) && $state->foundations[$card->suit] === $card->rank - 1) {
                $places[] = 'famille';
            }

            foreach ($state->tableau as $column => $cards) {
                if ('colonne' === $source[0] && $source[1] === $column) {
                    continue;
                }

                try {
                    $this->assertFits($cards, $card);
                    $places[] = 'colonne-'.$column;
                } catch (SolitaireException) {
                    // Cette pile ne prend pas la carte
                }
            }

            if ([] !== $places) {
                $targets[$code] = $places;
            }
        }

        return $targets;
    }

    /**
     * Fin de partie sans surprise : talon vide et toutes les cartes des piles visibles (les cartes retournées le sont aussi).
     */
    public function canFinishAlone(SolitaireState $state): bool
    {
        if ($state->finished || [] !== $state->stock) {
            return false;
        }

        foreach ($state->tableau as $column) {
            foreach ($column as [, $up]) {
                if (!$up) {
                    return false;
                }
            }
        }

        return true;
    }

    // ---------- Interne ----------

    /**
     * Où est la carte, et le bloc qui part avec elle.
     *
     * @return array{array{string, int|string}, list<string>}
     *
     * @throws SolitaireException
     */
    private function locate(SolitaireState $state, string $code): array
    {
        if ([] !== $state->waste && $state->waste[\count($state->waste) - 1] === $code) {
            return [['talon', 0], [$code]];
        }

        $card = SolitaireCard::fromCode($code);

        if ($state->foundations[$card->suit] === $card->rank) {
            return [['famille', $card->suit], [$code]];
        }

        foreach ($state->tableau as $column => $cards) {
            foreach ($cards as $row => [$held, $up]) {
                if ($held === $code) {
                    if (!$up) {
                        throw new SolitaireException('Cette carte est encore face cachée.');
                    }

                    return [['colonne', $column], array_map(static fn (array $entry): string => $entry[0], \array_slice($cards, $row))];
                }
            }
        }

        throw new SolitaireException('Cette carte ne peut pas être prise.');
    }

    /**
     * @param array{string, int|string} $source
     */
    private function take(SolitaireState $state, array $source, int $count): void
    {
        match ($source[0]) {
            'talon' => $this->takeFromWaste($state),
            'famille' => --$state->foundations[(string) $source[1]],
            default => array_splice($state->tableau[(int) $source[1]], -$count),
        };
    }

    /**
     * La carte du dessus des cartes retournées part : une de moins dans l'étalement.
     */
    private function takeFromWaste(SolitaireState $state): void
    {
        array_pop($state->waste);
        $state->fan = max(0, $state->fan - 1);
        $this->showLastCards($state);
    }

    /**
     * Il ne reste au talon (cartes cachées et retournées) que ses dernières cartes : celles encore cachées se retournent,
     * toutes restent visibles et le talon ne se clique plus.
     */
    private function showLastCards(SolitaireState $state): void
    {
        if ([] === $state->stock || \count($state->stock) + \count($state->waste) > $state->draw) {
            return;
        }

        while ([] !== $state->stock) {
            $state->waste[] = array_pop($state->stock);
        }

        $state->fan = \count($state->waste);
    }

    /**
     * @param list<array{string, bool}> $column
     *
     * @throws SolitaireException
     */
    private function assertFits(array $column, SolitaireCard $card): void
    {
        if ([] === $column) {
            if (13 !== $card->rank) {
                throw new SolitaireException('Seul un Roi va sur une pile vide.');
            }

            return;
        }

        [$code, $up] = $column[\count($column) - 1];
        $under = SolitaireCard::fromCode($code);

        if (!$up || $under->isRed() === $card->isRed() || $under->rank !== $card->rank + 1) {
            throw new SolitaireException('Il faut une couleur opposée et une hauteur juste au-dessus (un 9 rouge sur un 10 noir).');
        }
    }

    private function revealAndFinish(SolitaireState $state): void
    {
        foreach ($state->tableau as $index => $column) {
            $last = \count($column) - 1;

            if ($last >= 0 && !$column[$last][1]) {
                $state->tableau[$index][$last][1] = true;
                $state->score += SolitaireScore::REVEAL;
            }
        }

        if (52 === $state->sorted()) {
            $state->score += SolitaireScore::COMPLETE;
            $state->finished = true;
        }
    }

    private function remember(SolitaireState $state): void
    {
        // Une seule position gardée : celle d'avant ce déplacement
        $state->history = [[...$state->board(), 'score' => $state->score]];
    }

    private function assertPlaying(SolitaireState $state): void
    {
        if ($state->finished) {
            throw new SolitaireException('La partie est terminée.');
        }
    }
}
