<?php

declare(strict_types=1);

namespace App\Game\Patience;

use App\Enum\Game;
use App\Game\Freecell\FreecellRules;
use App\Game\GolfSolitaire\GolfSolitaireRules;
use App\Game\Pyramide\PyramideRules;
use App\Game\Solitaire\SolitaireRules;
use App\Game\Spider\SpiderRules;

/**
 * Les règles de chaque jeu de patience, d'après le jeu du salon.
 */
final class PatienceRulesFactory
{
    public static function for(Game $game): PatienceRules
    {
        $level = $game->spiderLevel();

        return match (true) {
            Game::Solitaire === $game => new SolitaireRules(3),
            Game::SolitaireHarmonie === $game => new SolitaireRules(1),
            null !== $level => new SpiderRules($level),
            Game::Freecell === $game => new FreecellRules(),
            Game::Pyramide === $game => new PyramideRules(),
            Game::GolfSolitaire === $game => new GolfSolitaireRules(),
            default => throw new \LogicException(\sprintf('« %s » n’est pas un jeu de patience.', $game->value)),
        };
    }
}
