<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PatienceBoard;
use App\Entity\PatienceGame;
use App\Game\Solitaire\SolitaireCard;
use App\Game\Solitaire\SolitaireEngine;
use App\Game\Solitaire\SolitaireScore;
use App\Game\Solitaire\SolitaireState;

/**
 * Ce qu'un joueur voit de sa grille de solitaire (les cartes cachées restent cachées) et où chaque carte peut aller.
 * Le reste de la vue (temps, manche, joueurs, résultats) vient de PatienceView.
 */
final readonly class SolitaireView
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
     * La grille du joueur : null pour une carte face cachée.
     *
     * @return array<string, mixed>
     */
    private function board(SolitaireState $state, PatienceBoard $board): array
    {
        $foundations = [];

        foreach ($state->foundations as $suit => $count) {
            $foundations[$suit] = 0 === $count ? null : (new SolitaireCard($suit, $count))->code();
        }

        return [
            'stock' => \count($state->stock),
            // Les cartes retournées du dernier clic sur le talon qui restent (seule la dernière se joue) ;
            // toutes jouées : la carte du dessus seule
            'waste' => $state->visibleWaste(),
            // La carte juste dessous, déjà affichée sous l'éventail : quand on prend celle du dessus, elle est là tout de suite
            'under' => $state->waste[\count($state->waste) - \count($state->visibleWaste()) - 1] ?? null,
            'wasteCount' => \count($state->waste),
            // Dernières cartes du talon, toutes visibles : le talon ne se clique plus
            'lastCards' => $state->lastCards(),
            'foundations' => $foundations,
            // Montées seules sur leur famille à ce coup (dans l'ordre) : la page les montre monter l'une après l'autre
            'auto' => array_map(static fn (array $move): array => ['card' => $move[0], 'from' => $move[1]], $state->auto),
            'tableau' => array_map(static fn (array $column): array => array_map(static fn (array $card): ?string => $card[1] ? $card[0] : null, $column), $state->tableau),
            'sorted' => $state->sorted(),
            // Prochain retournement du talon payant (à partir de la 4e consultation)
            'recycleCost' => [] === $state->stock && !$state->lastCards() && $state->passes + 1 >= SolitaireScore::FREE_PASSES ? -SolitaireScore::STOCK_PASS : 0,
            'canUndo' => (new SolitaireEngine())->canUndo($state),
            'undoCost' => -SolitaireScore::UNDO,
            'targets' => (object) (new SolitaireEngine())->targets($state),
            'details' => $state->finished ? $this->details($state, $board) : [],
        ];
    }

    /**
     * Le détail du score, pour l'écran de résultat.
     *
     * @return list<array{label: string, value: string}>
     */
    private function details(SolitaireState $state, PatienceBoard $board): array
    {
        $left = PatienceBoard::SECONDS - ($board->secondsPlayed() ?? PatienceBoard::SECONDS);

        return [
            ['label' => $state->isComplete() ? 'Jeu résolu' : 'Cartes rangées', 'value' => $state->isComplete() ? '+ '.number_format(SolitaireScore::COMPLETE, 0, ',', ' ').' points' : $state->sorted().' / 52'],
            ['label' => 'Temps restant', 'value' => \sprintf('%d:%02d (+%s points)', intdiv($left, 60), $left % 60, number_format($state->bonus, 0, ',', ' '))],
            ['label' => 'Talon consulté', 'value' => ($state->passes + 1).' fois'],
            ['label' => 'Retours', 'value' => $state->undos.' fois'],
        ];
    }
}
