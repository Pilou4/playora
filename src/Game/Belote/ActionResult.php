<?php

declare(strict_types=1);

namespace App\Game\Belote;

/**
 * Ce qu'une action a déclenché, dans l'ordre : sert aux animations (carte posée, pli ramassé,
 * nouvelle donne…) et à l'enregistrement des donnes terminées.
 */
final readonly class ActionResult
{
    /**
     * @param list<array<string, mixed>> $events
     * @param list<DealResult>           $deals
     */
    public function __construct(
        public array $events = [],
        public array $deals = [],
    ) {
    }

    public function merge(self $other): self
    {
        return new self([...$this->events, ...$other->events], [...$this->deals, ...$other->deals]);
    }
}
