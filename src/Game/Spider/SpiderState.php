<?php

declare(strict_types=1);

namespace App\Game\Spider;

use App\Game\Patience\PatienceState;

/**
 * Une partie de Spider, enregistrée en JSON. Les cartes sont des codes (« pique-roi-1 »).
 *
 * - columns : les 10 piles de cartes, chaque carte avec son côté (visible ou non) ;
 * - stock : les 5 piles talon de 10 cartes (vides une fois distribuées) ;
 * - foundations : la couleur de chaque famille complète rangée sur les piles de base (8 au plus) ;
 * - jokers : la carte posée sur chaque pile du Joker (null si vide).
 */
final class SpiderState implements PatienceState
{
    public const int MAX_UNDO = 100;

    /** @var list<list<array{string, bool}>> */
    public array $columns = [[], [], [], [], [], [], [], [], [], []];
    /** @var list<list<string>> */
    public array $stock = [];
    /** @var list<string> */
    public array $foundations = [];
    /** @var list<?string> */
    public array $jokers = [];
    /** Une carte a été posée sur une pile du Joker (plus de bonus « sans Joker »). */
    public bool $jokerUsed = false;
    /** Piles déjà payées pour avoir été vidées (une fois par pile). @var list<int> */
    public array $emptied = [];

    public int $score = 0;
    public int $moves = 0;
    public int $undos = 0;
    public bool $finished = false;
    /** Points ajoutés à la fin de la partie (tout rangé, sans joker, temps restant), pour l'écran de résultat. */
    public int $bonus = 0;
    /** Positions précédentes (avec le score), pour le bouton « Annuler ». @var list<array<string, mixed>> */
    public array $history = [];

    public function __construct(
        /** La même graine donne la même donne : tous les joueurs d'une partie ont les mêmes cartes. */
        public readonly int $seed,
        public readonly SpiderLevel $level,
    ) {
        $this->jokers = array_fill(0, $level->jokers(), null);
    }

    public function score(): int
    {
        return $this->score;
    }

    /** Toutes les familles rangées (104 cartes). */
    public function isComplete(): bool
    {
        return 8 === \count($this->foundations);
    }

    public function sortedCards(): int
    {
        return 13 * \count($this->foundations);
    }

    public function cardCount(): int
    {
        return 104;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    /**
     * La position seule, avec ce qui se rejoue quand on annule (score, Joker utilisé, piles vidées).
     *
     * @return array<string, mixed>
     */
    public function board(): array
    {
        return [
            'columns' => $this->columns,
            'stock' => $this->stock,
            'foundations' => $this->foundations,
            'jokers' => $this->jokers,
            'jokerUsed' => $this->jokerUsed,
            'emptied' => $this->emptied,
            'score' => $this->score,
        ];
    }

    /**
     * @param array<string, mixed> $board
     */
    public function restore(array $board): void
    {
        $this->columns = $board['columns'];
        $this->stock = $board['stock'];
        $this->foundations = $board['foundations'];
        $this->jokers = $board['jokers'];
        $this->jokerUsed = $board['jokerUsed'];
        $this->emptied = $board['emptied'];
        $this->score = $board['score'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'seed' => $this->seed,
            'level' => $this->level->value,
            ...$this->board(),
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
    public static function fromArray(array $data): self
    {
        $state = new self($data['seed'], SpiderLevel::from($data['level']));
        $state->restore($data);
        $state->moves = $data['moves'];
        $state->undos = $data['undos'];
        $state->finished = $data['finished'];
        $state->bonus = $data['bonus'] ?? 0;
        $state->history = $data['history'];

        return $state;
    }
}
