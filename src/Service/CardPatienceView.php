<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PatienceBoard;
use App\Entity\PatienceGame;
use App\Game\CardPatience\CardPatienceRules;
use App\Game\CardPatience\CardPatienceState;
use App\Game\Patience\PatienceRulesFactory;

/**
 * Ce qu'un joueur voit de sa grille de FreeCell, de Pyramide ou de Golf solitaire, et où chaque carte peut aller.
 * Le reste de la vue (temps, joueurs, résultats) vient de PatienceView.
 */
final readonly class CardPatienceView
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
        $rules = PatienceRulesFactory::for($game->getRoom()->getGame());
        \assert($rules instanceof CardPatienceRules);

        return $this->patience->forPlayer($game, $me, static function (CardPatienceState $state, PatienceBoard $board) use ($rules): array {
            $left = PatienceBoard::SECONDS - ($board->secondsPlayed() ?? PatienceBoard::SECONDS);

            return [
                ...$rules->cards($state),
                'sorted' => $state->sortedCards(),
                'cardCount' => $state->cardCount(),
                'canUndo' => !$state->finished && [] !== $state->history,
                'targets' => (object) ($state->finished ? [] : $rules->targets($state)),
                'details' => $state->finished ? $rules->details($state, $left) : [],
            ];
        });
    }
}
