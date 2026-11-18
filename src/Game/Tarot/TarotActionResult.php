<?php

declare(strict_types=1);

namespace App\Game\Tarot;

/**
 * Ce qu'une action a déclenché, dans l'ordre (animations), et les donnes terminées.
 */
final readonly class TarotActionResult
{
    /**
     * @param list<array<string, mixed>> $events
     * @param list<TarotDealResult>      $deals
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
