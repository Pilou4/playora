<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Tarot;

use App\Game\Tarot\Poignee;
use App\Game\Tarot\TarotContract;
use App\Game\Tarot\TarotScorer;
use PHPUnit\Framework\TestCase;

/**
 * Calcul des scores (FFT). Le preneur est la place 0.
 */
final class TarotScorerTest extends TestCase
{
    public function testMadeGarde(): void
    {
        // Garde, 2 bouts, 49 points : 8 de plus que 41 → (25 + 8) × 2 = 66
        $deal = TarotScorer::score(1, 3, 0, TarotContract::Garde, 98, 2, null, [], false, [10, 8]);

        self::assertTrue($deal->made);
        self::assertSame(66, $deal->value);
        self::assertSame([198, -66, -66, -66], $deal->scored);
    }

    public function testJustMadeAndFailed(): void
    {
        self::assertSame(25, TarotScorer::score(1, 3, 0, TarotContract::Petite, 102, 1, null, [], false, [9, 9])->value);

        // Petite, 0 bout, 50,5 points : chutée de 5,5 → arrondi à 6 → -(25 + 6)
        $failed = TarotScorer::score(1, 3, 0, TarotContract::Petite, 101, 0, null, [], false, [9, 9]);
        self::assertFalse($failed->made);
        self::assertSame(-31, $failed->value);
        self::assertSame([-93, 31, 31, 31], $failed->scored);
    }

    public function testPetitAuBoutIsMultipliedAndGoesToItsCamp(): void
    {
        // Garde sans faite de 0 : 25 × 4 = 100 ; le Petit au bout pour la défense : -10 × 4
        self::assertSame(60, TarotScorer::score(1, 3, 0, TarotContract::GardeSans, 82, 2, 1, [], false, [9, 9])->value);
        self::assertSame(140, TarotScorer::score(1, 3, 0, TarotContract::GardeSans, 82, 2, 0, [], false, [9, 9])->value);
    }

    public function testPoigneeGoesToTheWinningCampWithoutMultiplier(): void
    {
        // Garde chutée de 5 : -(25 + 5) × 2 = -60 ; la poignée simple (20), annoncée par n'importe qui, va à la défense
        self::assertSame(-80, TarotScorer::score(1, 3, 0, TarotContract::Garde, 72, 2, null, [2 => Poignee::Simple], false, [9, 9])->value);
    }

    public function testChelem(): void
    {
        $announced = TarotScorer::score(1, 3, 0, TarotContract::Garde, 182, 3, 0, [], true, [18, 0]);
        self::assertSame(0, $announced->chelem);
        // (25 + 55) × 2 + Petit au bout 20 + chelem annoncé 400
        self::assertSame(580, $announced->value);

        // Chelem annoncé mais raté : garde faite de 24 → (25 + 24) × 2 = 98, moins 200
        self::assertSame(-102, TarotScorer::score(1, 3, 0, TarotContract::Garde, 120, 3, null, [], true, [17, 1])->value);
    }

    public function testThreeAndFivePlayers(): void
    {
        // 3 joueurs : le preneur marque 2 fois
        self::assertSame([132, -66, -66], TarotScorer::score(1, 2, 0, TarotContract::Garde, 98, 2, null, [], false, [10, 8], 3)->scored);
        // 5 joueurs : preneur 2 fois, partenaire 1 fois, chaque défenseur -1
        self::assertSame([132, -66, 66, -66, -66], TarotScorer::score(1, 4, 0, TarotContract::Garde, 98, 2, null, [], false, [10, 8], 5, 2)->scored);
        // 5 joueurs, seul : 4 fois
        self::assertSame([264, -66, -66, -66, -66], TarotScorer::score(1, 4, 0, TarotContract::Garde, 98, 2, null, [], false, [10, 8], 5)->scored);
    }

    public function testScoresAlwaysAddUpToZero(): void
    {
        foreach (TarotContract::cases() as $contract) {
            $deal = TarotScorer::score(1, 0, 2, $contract, 77, 1, 1, [0 => Poignee::Double], false, [8, 10]);
            self::assertSame(0, array_sum($deal->scored));
            self::assertSame(3 * $deal->value, $deal->scored[2]);
        }
    }
}
