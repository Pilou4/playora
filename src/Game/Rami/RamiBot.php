<?php

declare(strict_types=1);

namespace App\Game\Rami;

/**
 * Robot de Rami : il pioche, pose dès qu'il peut (première pose comprise), complète les combinaisons posées,
 * puis défausse sa carte la moins utile. Ses coups passent par le moteur, comme ceux d'un joueur.
 */
final readonly class RamiBot
{
    public function __construct(
        private RamiEngine $engine,
    ) {
    }

    /** Joue le tour du joueur dont c'est le tour, jusqu'à sa défausse (ou la fin de la manche). */
    public function play(RamiState $state): void
    {
        $position = $state->turn;

        if (RamiState::DRAW === $state->phase) {
            $this->engine->draw($state, $position);
        }

        if (RamiState::PLAY !== $state->phase) {
            return;
        }

        $this->meld($state, $position);

        if ($state->opened[$position]) {
            $this->layOff($state, $position);
        }

        if (RamiState::PLAY === $state->phase && $state->turn === $position) {
            $this->engine->discard($state, $position, RamiHand::worst($state->hands[$position]));
        }
    }

    private function meld(RamiState $state, int $position): void
    {
        $groups = RamiHand::melds($state->hands[$position]);

        if ([] === $groups) {
            return;
        }

        try {
            $this->engine->meld($state, $position, $groups);
        } catch (RamiException) {
            // Pas encore assez de points pour la première pose : on attend
        }
    }

    private function layOff(RamiState $state, int $position): void
    {
        foreach ($state->hands[$position] as $code) {
            if (str_starts_with($code, 'joker-') || RamiState::PLAY !== $state->phase) {
                continue;
            }

            foreach (array_keys($state->melds) as $index) {
                try {
                    $this->engine->layOff($state, $position, $code, $index);

                    break;
                } catch (RamiException) {
                }
            }
        }
    }
}
