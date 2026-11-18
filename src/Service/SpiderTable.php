<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PatienceGame;
use App\Entity\User;
use App\Game\Spider\SpiderEngine;
use App\Game\Spider\SpiderException;
use App\Game\Spider\SpiderState;

/**
 * Les coups propres au Spider. La partie elle-même (temps, robots, abandon) est gérée par PatienceTable.
 */
final readonly class SpiderTable
{
    private SpiderEngine $engine;

    public function __construct(
        private PatienceTable $table,
    ) {
        $this->engine = new SpiderEngine();
    }

    /**
     * Déplacer une carte (et celles posées dessus) vers « colonne-0 » à « colonne-9 » ou « joker-0 », « joker-1 ».
     *
     * @throws SpiderException
     */
    public function move(User $user, PatienceGame $game, string $card, string $target): void
    {
        $this->table->act($user, $game, fn (SpiderState $state) => $this->engine->move($state, $card, $target));
    }

    /**
     * Distribuer une pile talon (0 à 4).
     *
     * @throws SpiderException
     */
    public function deal(User $user, PatienceGame $game, int $pile): void
    {
        $this->table->act($user, $game, fn (SpiderState $state) => $this->engine->dealStock($state, $pile));
    }

    /**
     * Bouton « Annuler » (le coût dépend du niveau).
     *
     * @throws SpiderException
     */
    public function undo(User $user, PatienceGame $game): void
    {
        $this->table->act($user, $game, fn (SpiderState $state) => $this->engine->undo($state));
    }
}
