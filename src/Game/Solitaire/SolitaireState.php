<?php

declare(strict_types=1);

namespace App\Game\Solitaire;

use App\Game\Patience\PatienceState;

/**
 * Une partie de solitaire (Klondike), enregistrée en JSON. Les cartes sont des codes (« coeur-as »).
 *
 * - stock : le talon, face cachée (la carte du dessus est la dernière) ;
 * - waste : les cartes retournées du talon (la dernière est jouable) ;
 * - fan : combien des dernières cartes retournées restent étalées (celles du dernier clic sur le talon, moins celles jouées) ;
 * - foundations : nombre de cartes rangées sur la pile de chaque famille (0 à 13) ;
 * - tableau : les 7 piles de tri, chaque carte avec son côté (visible ou non).
 */
final class SolitaireState implements PatienceState
{
    /** Un seul retour possible : le dernier déplacement de carte. */
    public const int MAX_UNDO = 1;

    /** @var list<string> */
    public array $stock = [];
    /** @var list<string> */
    public array $waste = [];
    /** Cartes étalées depuis le dernier clic sur le talon (3, complétées par les précédentes s'il en restait moins ; puis 2, 1, 0 en les jouant). */
    public int $fan = 0;
    /** @var array<string, int> */
    public array $foundations = ['pique' => 0, 'coeur' => 0, 'carreau' => 0, 'trefle' => 0];
    /** @var list<list<array{string, bool}>> */
    public array $tableau = [[], [], [], [], [], [], []];

    /** Cartes retournées à chaque clic sur le talon : 3 (Solitaire) ou 1 (Solitaire harmonie). */
    public int $draw = 1;
    public int $score = 0;
    /** Nombre de fois où le talon a été remis en place (le 1er passage ne compte pas). */
    public int $passes = 0;
    public int $moves = 0;
    public int $undos = 0;
    /** Cartes déjà payées en arrivant sur leur famille (une carte ne rapporte qu'une fois). @var list<string> */
    public array $scored = [];
    public bool $finished = false;
    /** Points du temps restant ajoutés à la fin de la partie, pour l'écran de résultat. */
    public int $bonus = 0;
    /** Cartes montées seules sur leur famille au dernier coup, dans l'ordre, avec d'où elles partent (« talon », « colonne-N »). @var list<array{string, string}> */
    public array $auto = [];
    /** Positions précédentes, pour le bouton « retour ». @var list<array<string, mixed>> */
    public array $history = [];

    public function __construct(
        /** La même graine donne la même donne : tous les joueurs d'un défi ont les mêmes cartes. */
        public readonly int $seed,
    ) {
    }

    public function sorted(): int
    {
        return array_sum($this->foundations);
    }

    public function score(): int
    {
        return $this->score;
    }

    public function isComplete(): bool
    {
        return 52 === $this->sorted();
    }

    public function sortedCards(): int
    {
        return $this->sorted();
    }

    public function cardCount(): int
    {
        return 52;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    /**
     * Les cartes retournées qu'on voit : celles du dernier clic sur le talon qui restent, étalées ;
     * une fois toutes jouées, seulement la carte du dessus de la pile.
     *
     * @return list<string>
     */
    public function visibleWaste(): array
    {
        // Les dernières cartes du talon : toutes visibles, étalées
        if ($this->lastCards()) {
            return $this->waste;
        }

        return [] === $this->waste ? [] : \array_slice($this->waste, -max(1, min($this->fan, \count($this->waste))));
    }

    /**
     * Il ne reste au talon que ses dernières cartes (pas plus qu'un clic n'en retourne) : elles sont toutes retournées
     * et visibles, et le talon ne se clique plus (le retourner ne montrerait que les mêmes cartes).
     */
    public function lastCards(): bool
    {
        return [] === $this->stock && [] !== $this->waste && \count($this->waste) <= $this->draw;
    }

    /**
     * La position des cartes seule (pour le retour en arrière).
     *
     * @return array<string, mixed>
     */
    public function board(): array
    {
        return ['stock' => $this->stock, 'waste' => $this->waste, 'fan' => $this->fan, 'foundations' => $this->foundations, 'tableau' => $this->tableau];
    }

    /**
     * @param array<string, mixed> $board
     */
    public function restore(array $board): void
    {
        $this->stock = $board['stock'];
        $this->waste = $board['waste'];
        // Parties commencées avant l'étalement : une seule carte montrée
        $this->fan = $board['fan'] ?? 0;
        $this->foundations = $board['foundations'];
        $this->tableau = $board['tableau'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'seed' => $this->seed,
            'draw' => $this->draw,
            ...$this->board(),
            'score' => $this->score,
            'passes' => $this->passes,
            'moves' => $this->moves,
            'undos' => $this->undos,
            'scored' => $this->scored,
            'finished' => $this->finished,
            'bonus' => $this->bonus,
            'auto' => $this->auto,
            'history' => $this->history,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $state = new self($data['seed']);
        $state->restore($data);
        // Parties commencées avant le Solitaire 3 cartes : une carte à la fois
        $state->draw = $data['draw'] ?? 1;
        $state->score = $data['score'];
        $state->passes = $data['passes'];
        $state->moves = $data['moves'];
        $state->undos = $data['undos'];
        $state->scored = $data['scored'];
        $state->finished = $data['finished'];
        $state->bonus = $data['bonus'] ?? 0;
        $state->auto = $data['auto'] ?? [];
        $state->history = $data['history'];

        return $state;
    }
}
