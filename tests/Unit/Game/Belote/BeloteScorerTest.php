<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Belote;

use App\Game\Belote\BeloteScorer;
use App\Game\Belote\Outcome;
use App\Game\Belote\Suit;
use PHPUnit\Framework\TestCase;

/**
 * Le preneur est à la place 0 (équipe 0) dans tous ces tests.
 */
final class BeloteScorerTest extends TestCase
{
    public function testContractMadeEachTeamScoresItsPoints(): void
    {
        $deal = BeloteScorer::score(1, 3, 0, Suit::Hearts, [100, 62], [5, 3], null);

        self::assertSame(Outcome::Made, $deal->outcome);
        self::assertSame([100, 62], $deal->scored);
    }

    public function testContractFailedDefenseScores162(): void
    {
        $deal = BeloteScorer::score(1, 3, 0, Suit::Hearts, [70, 92], [3, 5], null);

        self::assertSame(Outcome::Failed, $deal->outcome);
        self::assertSame([0, 162], $deal->scored);
    }

    public function testBeloteIsKeptEvenWhenTheContractFails(): void
    {
        $deal = BeloteScorer::score(1, 3, 0, Suit::Hearts, [60, 102], [3, 5], 0);

        self::assertSame(Outcome::Failed, $deal->outcome);
        self::assertSame([20, 162], $deal->scored);
    }

    public function testBeloteCountsToMakeTheContract(): void
    {
        // 76 + 20 = 96 contre 86 : réussi
        $deal = BeloteScorer::score(1, 3, 0, Suit::Hearts, [76, 86], [4, 4], 0);

        self::assertSame(Outcome::Made, $deal->outcome);
        self::assertSame([96, 86], $deal->scored);
    }

    public function testWithABeloteTheTakerNeeds92(): void
    {
        // Belote à la défense : 91 contre 71 + 20 = 91, litige ; 92 contre 70 + 20 : réussi
        self::assertSame(Outcome::Litige, BeloteScorer::score(1, 3, 0, Suit::Hearts, [91, 71], [4, 4], 1)->outcome);
        self::assertSame(Outcome::Made, BeloteScorer::score(1, 3, 0, Suit::Hearts, [92, 70], [4, 4], 1)->outcome);
    }

    public function testLitigeTheTakerLosesItsPoints(): void
    {
        $deal = BeloteScorer::score(1, 3, 0, Suit::Hearts, [81, 81], [4, 4], null);

        self::assertSame(Outcome::Litige, $deal->outcome);
        self::assertSame([0, 81], $deal->scored);
        self::assertSame(0, $deal->pendingAfter);
    }

    public function testCapot(): void
    {
        $deal = BeloteScorer::score(1, 3, 0, Suit::Hearts, [162, 0], [8, 0], null);

        self::assertSame(0, $deal->capotTeam);
        self::assertSame([252, 0], $deal->scored);
    }

    public function testCapotAgainstTheTaker(): void
    {
        $deal = BeloteScorer::score(1, 3, 0, Suit::Hearts, [0, 162], [0, 8], 0);

        self::assertSame(Outcome::Failed, $deal->outcome);
        self::assertSame([20, 252], $deal->scored);
    }

    public function testAnnouncementsCountToMakeTheContract(): void
    {
        // 75 + cinquante 50 = 125 contre 87 : réussi, le preneur marque ses points et son annonce
        $deal = BeloteScorer::score(1, 3, 0, Suit::Hearts, [75, 87], [4, 4], null, [50, 0]);

        self::assertSame(Outcome::Made, $deal->outcome);
        self::assertSame([125, 87], $deal->scored);
    }

    public function testFailedTakerLosesItsAnnouncementsToTheDefense(): void
    {
        $deal = BeloteScorer::score(1, 3, 0, Suit::Hearts, [50, 112], [3, 5], null, [20, 0]);

        self::assertSame(Outcome::Failed, $deal->outcome);
        self::assertSame([0, 182], $deal->scored);
    }
}
