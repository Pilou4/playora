<?php

declare(strict_types=1);

namespace App\Game\Spider;

use App\Game\Patience\PatienceRules;
use App\Game\Patience\PatienceState;

/**
 * Un des trois Spider vu d'une partie de patience : donne du niveau, robot, fin de grille.
 */
final readonly class SpiderRules implements PatienceRules
{
    private SpiderBot $bot;

    public function __construct(
        private SpiderLevel $level,
        private SpiderEngine $engine = new SpiderEngine(),
    ) {
        $this->bot = new SpiderBot($this->engine);
    }

    public function engine(): SpiderEngine
    {
        return $this->engine;
    }

    public function deal(int $seed): SpiderState
    {
        return $this->engine->deal($seed, $this->level);
    }

    public function restore(array $data): SpiderState
    {
        return SpiderState::fromArray($data);
    }

    public function botPlay(PatienceState $state): void
    {
        $this->bot->play($this->state($state));
    }

    public function finish(PatienceState $state, int $secondsLeft, bool $complete): void
    {
        $this->engine->finish($this->state($state), $secondsLeft, $complete);
    }

    /** Les familles complètes partent déjà seules : rien de plus après un coup. */
    public function afterMove(PatienceState $state, bool $auto): void
    {
    }

    private function state(PatienceState $state): SpiderState
    {
        \assert($state instanceof SpiderState);

        return $state;
    }
}
