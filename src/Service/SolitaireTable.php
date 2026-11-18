<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PatienceGame;
use App\Entity\User;
use App\Game\Solitaire\SolitaireEngine;
use App\Game\Solitaire\SolitaireException;
use App\Game\Solitaire\SolitaireState;

/**
 * Les coups propres au solitaire. La partie elle-même (manches, temps, robots, abandon) est gérée par PatienceTable.
 */
final readonly class SolitaireTable
{
    private SolitaireEngine $engine;

    public function __construct(
        private PatienceTable $table,
    ) {
        $this->engine = new SolitaireEngine();
    }

    /**
     * Clic sur le talon.
     *
     * @throws SolitaireException
     */
    public function draw(User $user, PatienceGame $game, bool $auto): void
    {
        $this->table->act($user, $game, fn (SolitaireState $state) => $this->engine->draw($state), $auto);
    }

    /**
     * Déplacer une carte vers « famille » ou « colonne-0 » à « colonne-6 ».
     *
     * @throws SolitaireException
     */
    public function move(User $user, PatienceGame $game, string $card, string $target, bool $auto): void
    {
        $this->table->act($user, $game, fn (SolitaireState $state) => $this->engine->move($state, $card, $target), $auto);
    }

    /**
     * Bouton « Retour » (1 000 points).
     *
     * @throws SolitaireException
     */
    public function undo(User $user, PatienceGame $game): void
    {
        $this->table->act($user, $game, fn (SolitaireState $state) => $this->engine->undo($state));
    }
}
