<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PatienceBoard;
use App\Entity\PatienceGame;
use App\Game\Spider\SpiderEngine;
use App\Game\Spider\SpiderState;

/**
 * Ce qu'un joueur voit de sa grille de Spider (les cartes cachées restent cachées) et où chaque carte peut aller.
 * Le reste de la vue (temps, joueurs, résultats) vient de PatienceView.
 */
final readonly class SpiderView
{
    public function __construct(
        private PatienceView $patience,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forPlayer(PatienceGame $game, int $me): array
    {
        return $this->patience->forPlayer($game, $me, $this->board(...));
    }

    /**
     * La grille du joueur : null pour une carte face cachée. Talon : le nombre de cartes de chaque pile,
     * et ce qu'on en voit (la première carte, ou toute la pile au Pro).
     *
     * @return array<string, mixed>
     */
    private function board(SpiderState $state, PatienceBoard $board): array
    {
        $level = $state->level;
        $engine = new SpiderEngine();

        return [
            'level' => $level->value,
            'columns' => array_map(static fn (array $column): array => array_map(static fn (array $card): ?string => $card[1] ? $card[0] : null, $column), $state->columns),
            'stock' => array_map(static fn (array $pile): array => [
                'count' => \count($pile),
                'shown' => [] === $pile ? [] : ($level->showsWholeStock() ? $pile : [$pile[0]]),
            ], $state->stock),
            'foundations' => $state->foundations,
            'jokers' => $state->jokers,
            'sorted' => $state->sortedCards(),
            'canDeal' => $engine->canDealStock($state),
            'canUndo' => !$state->finished && [] !== $state->history && $state->score >= $level->undoCost(),
            'undoCost' => $level->undoCost(),
            'targets' => (object) $engine->targets($state),
            'details' => $state->finished ? $this->details($state, $board) : [],
        ];
    }

    /**
     * Le détail du score, pour l'écran de résultat.
     *
     * @return list<array{label: string, value: string}>
     */
    private function details(SpiderState $state, PatienceBoard $board): array
    {
        $left = PatienceBoard::SECONDS - ($board->secondsPlayed() ?? PatienceBoard::SECONDS);

        return [
            ['label' => 'Cartes rangées', 'value' => $state->sortedCards().' / '.$state->cardCount()],
            ['label' => 'Temps restant', 'value' => \sprintf('%d:%02d', intdiv($left, 60), $left % 60)],
            ['label' => 'Bonus de fin', 'value' => '+ '.number_format($state->bonus, 0, ',', ' ').' points'],
            ['label' => 'Retours', 'value' => $state->undos.' fois'],
        ];
    }
}
