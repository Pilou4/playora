<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Solitaire;

use App\Game\Solitaire\SolitaireBot;
use App\Game\Solitaire\SolitaireEngine;
use App\Game\Solitaire\SolitaireScore;
use PHPUnit\Framework\TestCase;

/**
 * Robot de solitaire (mode test) : il joue toute sa donne, sans jamais payer le talon ni dépasser le temps.
 */
final class SolitaireBotTest extends TestCase
{
    public function testTheBotPlaysAWholeDealAndStops(): void
    {
        $engine = new SolitaireEngine();
        $bot = new SolitaireBot($engine);
        $complete = 0;

        foreach (range(1, 60) as $seed) {
            $state = $engine->deal($seed);
            $seconds = $bot->play($state);

            self::assertTrue($state->finished, 'donne '.$seed);
            self::assertGreaterThan(0, $seconds);
            self::assertLessThanOrEqual(SolitaireScore::SECONDS, $seconds);
            // Jamais de retournement payant du talon, jamais de retour
            self::assertLessThan(SolitaireScore::FREE_PASSES, $state->passes, 'donne '.$seed);
            self::assertSame(0, $state->undos);
            self::assertGreaterThan(0, $state->score);
            $complete += 52 === $state->sorted() ? 1 : 0;
        }

        // Il gagne parfois, sans être imbattable
        self::assertGreaterThan(0, $complete);
        self::assertLessThan(60, $complete);
    }
}
