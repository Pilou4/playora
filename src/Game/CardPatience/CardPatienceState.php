<?php

declare(strict_types=1);

namespace App\Game\CardPatience;

use App\Game\Patience\PatienceState;

/**
 * Ce qu'ont en commun les grilles du FreeCell, de la Pyramide et du Golf solitaire : score, coups, retours, fin,
 * positions précédentes (pour « Retour »). Chaque jeu ajoute ses cartes (board / restore) et dit quand tout est fini.
 */
abstract class CardPatienceState implements PatienceState
{
    public const int MAX_UNDO = 100;

    public int $score = 0;
    public int $moves = 0;
    public int $undos = 0;
    public bool $finished = false;
    /** Points du temps restant ajoutés à la fin, pour l'écran de résultat. */
    public int $bonus = 0;
    /** @var list<array<string, mixed>> */
    public array $history = [];

    public function __construct(
        /** La même graine donne la même donne à tous les joueurs du duel. */
        public readonly int $seed,
    ) {
    }

    /**
     * Les cartes de la grille (enregistrées, et remises par « Retour »).
     *
     * @return array<string, mixed>
     */
    abstract public function board(): array;

    /**
     * @param array<string, mixed> $board
     */
    abstract public function restore(array $board): void;

    public function score(): int
    {
        return $this->score;
    }

    public function isComplete(): bool
    {
        return $this->sortedCards() === $this->cardCount();
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    public function toArray(): array
    {
        return [
            'seed' => $this->seed,
            'board' => $this->board(),
            'score' => $this->score,
            'moves' => $this->moves,
            'undos' => $this->undos,
            'finished' => $this->finished,
            'bonus' => $this->bonus,
            'history' => $this->history,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        /** @phpstan-ignore new.static */
        $state = new static((int) $data['seed']);
        $state->restore($data['board']);
        $state->score = (int) $data['score'];
        $state->moves = (int) ($data['moves'] ?? 0);
        $state->undos = (int) ($data['undos'] ?? 0);
        $state->finished = (bool) ($data['finished'] ?? false);
        $state->bonus = (int) ($data['bonus'] ?? 0);
        $state->history = $data['history'] ?? [];

        return $state;
    }
}
