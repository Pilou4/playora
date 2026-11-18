<?php

declare(strict_types=1);

namespace App\Game\Golf;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Golf (6 cartes). Chaque coup est vérifié ici.
 *
 * - Chacun a 6 cartes cachées en 2 lignes de 3, et en retourne 2 au début.
 * - À son tour, on prend la carte de la défausse et on l'échange contre une de ses cartes ; ou on pioche :
 *   on échange la carte piochée, ou on la jette et on retourne une de ses cartes cachées.
 * - Dès qu'un joueur a retourné ses 6 cartes, chacun des autres joue une dernière fois, puis on compte :
 *   As 1, 2 = −2, 3 à 10 leur valeur, Valet et Dame 10, Roi 0 ; deux cartes de même hauteur dans une colonne : 0.
 */
final class GolfEngine
{
    public const int START_FLIPS = 2;

    /** Valeur supposée d'une carte cachée, pour les robots */
    private const float HIDDEN_GUESS = 5.0;

    public function __construct(
        /** Pour remélanger la défausse quand la pioche est vide */
        private readonly Randomizer $randomizer = new Randomizer(),
    ) {
    }

    /**
     * Donne d'un trou : 6 cartes chacun ; deux jeux à partir de 5 joueurs. La première carte de la pioche est retournée
     * sur la défausse ; le joueur après le donneur commencera, une fois que chacun aura retourné 2 cartes.
     */
    public function deal(int $seed, int $players, int $dealer): GolfState
    {
        $state = new GolfState($players, $dealer);
        $cards = (new Randomizer(new Xoshiro256StarStar($seed)))->shuffleArray(GolfCard::deck($players >= 5 ? 2 : 1));
        $codes = array_map(static fn (GolfCard $card): string => $card->code(), $cards);

        foreach (range(0, $players - 1) as $position) {
            $state->grids[$position] = array_splice($codes, 0, GolfState::SIZE);
        }

        $state->discard = [array_pop($codes)];
        $state->stock = $codes;
        $state->turn = ($dealer + 1) % $players;

        return $state;
    }

    /**
     * Début du trou : le joueur retourne une de ses cartes (2 en tout). Quand tout le monde l'a fait, le jeu commence.
     *
     * @throws GolfException
     */
    public function reveal(GolfState $state, int $position, int $index): void
    {
        if (GolfState::START !== $state->phase) {
            throw new GolfException('Les 2 premières cartes sont déjà retournées.');
        }

        $this->assertPosition($state, $position);

        if ($state->upCount($position) >= self::START_FLIPS) {
            throw new GolfException('Vous avez déjà retourné vos 2 cartes : attendez les autres joueurs.');
        }

        $this->flip($state, $position, $index);

        foreach (range(0, $state->players - 1) as $player) {
            if ($state->upCount($player) < self::START_FLIPS) {
                return;
            }
        }

        $state->phase = GolfState::PLAY;
    }

    /**
     * Piocher une carte (à échanger, ou à jeter en retournant une carte cachée).
     *
     * @throws GolfException
     */
    public function draw(GolfState $state, int $position): void
    {
        $this->assertTurn($state, $position);

        if (null !== $state->drawn) {
            throw new GolfException('Vous avez déjà pioché : échangez la carte ou jetez-la.');
        }

        $this->refill($state);
        $state->drawn = array_pop($state->stock) ?? throw new GolfException('La pioche est vide : prenez la carte de la défausse.');
    }

    /**
     * Prendre la carte de la défausse et l'échanger contre une de ses cartes.
     *
     * @throws GolfException
     */
    public function takeDiscard(GolfState $state, int $position, int $index): void
    {
        $this->assertTurn($state, $position);

        if (null !== $state->drawn) {
            throw new GolfException('Vous avez pioché : échangez la carte piochée ou jetez-la.');
        }

        $card = array_pop($state->discard) ?? throw new GolfException('La défausse est vide.');
        $this->replace($state, $position, $index, $card);
        $this->endTurn($state, $position);
    }

    /**
     * Échanger la carte piochée contre une de ses cartes.
     *
     * @throws GolfException
     */
    public function swap(GolfState $state, int $position, int $index): void
    {
        $this->assertTurn($state, $position);
        $card = $state->drawn ?? throw new GolfException('Piochez d’abord une carte, ou prenez celle de la défausse.');

        $state->drawn = null;
        $this->replace($state, $position, $index, $card);
        $this->endTurn($state, $position);
    }

    /**
     * Jeter la carte piochée et retourner une de ses cartes cachées.
     *
     * @throws GolfException
     */
    public function discardAndReveal(GolfState $state, int $position, int $index): void
    {
        $this->assertTurn($state, $position);
        $card = $state->drawn ?? throw new GolfException('Piochez d’abord une carte.');

        $this->flip($state, $position, $index);
        $state->drawn = null;
        $state->discard[] = $card;
        $this->endTurn($state, $position);
    }

    /**
     * Points d'une grille : chaque colonne compte ses deux cartes, ou 0 si elles ont la même hauteur.
     *
     * @param list<string> $grid
     */
    public function score(array $grid): int
    {
        $total = 0;

        for ($column = 0; $column < GolfState::COLUMNS; ++$column) {
            $top = GolfCard::fromCode($grid[$column]);
            $bottom = GolfCard::fromCode($grid[$column + GolfState::COLUMNS]);
            $total += $top->rank === $bottom->rank ? 0 : $top->points() + $bottom->points();
        }

        return $total;
    }

    /**
     * Joue à la place d'un joueur, simplement : temps écoulé, joueur parti ou robot.
     * Au début : il retourne ses 2 cartes (pour tous les joueurs en retard, si on le lui demande).
     * Ensuite : il prend la défausse si elle l'arrange vraiment, sinon il pioche ; il garde la carte si elle fait baisser
     * ses points (paire dans une colonne, carte faible), sinon il la jette et retourne une carte cachée.
     *
     * @param int|null $position au début du trou : le joueur à faire retourner (null : tous ceux qui sont en retard)
     */
    public function autoPlay(GolfState $state, ?int $position = null): void
    {
        if (GolfState::START === $state->phase) {
            foreach (null === $position ? range(0, $state->players - 1) : [$position] as $player) {
                while (GolfState::START === $state->phase && $state->upCount($player) < self::START_FLIPS) {
                    $this->reveal($state, $player, $this->firstHidden($state, $player));
                }
            }

            return;
        }

        if (GolfState::PLAY !== $state->phase) {
            return;
        }

        $player = $state->turn;
        $now = $this->estimate($state, $player);

        if (null === $state->drawn) {
            $top = $state->top();

            if (null !== $top) {
                [$index, $after] = $this->bestPlace($state, $player, $top);

                // La défausse : seulement si elle fait gagner au moins 2 points (ou si la pioche est épuisée)
                if ($now - $after >= 2 || ([] === $state->stock && 1 === \count($state->discard))) {
                    $this->takeDiscard($state, $player, $index);

                    return;
                }
            }

            $this->draw($state, $player);
        }

        [$index, $after] = $this->bestPlace($state, $player, (string) $state->drawn);

        // Plus aucune carte cachée (ne devrait pas arriver à son tour) : il échange quand même
        if ($after < $now - 0.5 || GolfState::SIZE === $state->upCount($player)) {
            $this->swap($state, $player, $index);
        } else {
            $this->discardAndReveal($state, $player, $this->firstHidden($state, $player));
        }
    }

    // ---------- Interne ----------

    /**
     * La meilleure place pour cette carte, et les points estimés de la grille ensuite.
     *
     * @return array{int, float}
     */
    private function bestPlace(GolfState $state, int $position, string $card): array
    {
        $best = [0, \INF];

        for ($index = 0; $index < GolfState::SIZE; ++$index) {
            $estimate = $this->estimate($state, $position, $index, $card);

            if ($estimate < $best[1]) {
                $best = [$index, $estimate];
            }
        }

        return $best;
    }

    /**
     * Points estimés d'une grille (les cartes cachées valent 5), avec éventuellement une carte posée à une place.
     */
    private function estimate(GolfState $state, int $position, ?int $index = null, ?string $card = null): float
    {
        $total = 0.0;

        for ($column = 0; $column < GolfState::COLUMNS; ++$column) {
            $cards = [];

            foreach ([$column, $column + GolfState::COLUMNS] as $slot) {
                $cards[] = $slot === $index ? GolfCard::fromCode((string) $card) : ($state->up[$position][$slot] ? GolfCard::fromCode($state->grids[$position][$slot]) : null);
            }

            if (null !== $cards[0] && null !== $cards[1] && $cards[0]->rank === $cards[1]->rank) {
                continue;
            }

            foreach ($cards as $known) {
                $total += null === $known ? self::HIDDEN_GUESS : $known->points();
            }
        }

        return $total;
    }

    private function firstHidden(GolfState $state, int $position): int
    {
        $index = array_search(false, $state->up[$position], true);

        return false === $index ? 0 : $index;
    }

    /**
     * @throws GolfException
     */
    private function replace(GolfState $state, int $position, int $index, string $card): void
    {
        $this->assertIndex($index);
        $state->discard[] = $state->grids[$position][$index];
        $state->grids[$position][$index] = $card;
        $state->up[$position][$index] = true;
    }

    /**
     * @throws GolfException
     */
    private function flip(GolfState $state, int $position, int $index): void
    {
        $this->assertIndex($index);

        if ($state->up[$position][$index]) {
            throw new GolfException('Cette carte est déjà retournée.');
        }

        $state->up[$position][$index] = true;
    }

    /**
     * Fin du tour : le premier joueur à avoir tout retourné déclenche le dernier tour ; quand le tour lui revient, le trou est fini.
     */
    private function endTurn(GolfState $state, int $position): void
    {
        if (null === $state->closer && GolfState::SIZE === $state->upCount($position)) {
            $state->closer = $position;
        }

        $state->turn = ($position + 1) % $state->players;

        if ($state->turn === $state->closer) {
            $this->endHole($state);
        }
    }

    private function endHole(GolfState $state): void
    {
        $state->phase = GolfState::OVER;
        $state->drawn = null;

        foreach ($state->grids as $position => $grid) {
            $state->up[$position] = array_fill(0, GolfState::SIZE, true);
            $state->points[$position] = $this->score($grid);
        }
    }

    /**
     * Pioche vide : la défausse est remélangée (sa carte du dessus reste).
     */
    private function refill(GolfState $state): void
    {
        if ([] === $state->stock && \count($state->discard) > 1) {
            $top = array_pop($state->discard);
            $state->stock = $this->randomizer->shuffleArray($state->discard);
            $state->discard = [$top];
        }
    }

    /**
     * @throws GolfException
     */
    private function assertIndex(int $index): void
    {
        if ($index < 0 || $index >= GolfState::SIZE) {
            throw new GolfException('Cette place n’existe pas.');
        }
    }

    /**
     * @throws GolfException
     */
    private function assertPosition(GolfState $state, int $position): void
    {
        if ($position < 0 || $position >= $state->players) {
            throw new GolfException('Vous n’êtes pas à cette table.');
        }
    }

    /**
     * @throws GolfException
     */
    private function assertTurn(GolfState $state, int $position): void
    {
        if (GolfState::START === $state->phase) {
            throw new GolfException('Retournez d’abord 2 cartes.');
        }

        if ($state->isOver()) {
            throw new GolfException('Le trou est terminé.');
        }

        if ($state->turn !== $position) {
            throw new GolfException('Ce n’est pas votre tour.');
        }
    }
}
