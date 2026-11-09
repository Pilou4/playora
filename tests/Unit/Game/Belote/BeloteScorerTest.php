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
        $deal = BeloteScorer::score(1, 3, 0, Suit::Hearts, [100, 62], [5, 3], null, 0);

        self::assertSame(Outcome::Made, $deal->outcome);
        self::assertSame([100, 62], $deal->scored);
    }

    public function testContractFailedDefenseScores162(): void
    {
        $deal = BeloteScorer::score(1, 3, 0, Suit::Hearts, [70, 92], [3, 5], null, 0);

        self::assertSame(Outcome::Failed, $deal->outcome);
        self::assertSame([0, 162], $deal->scored);
    }

    public function testBeloteIsKeptEvenWhenTheContractFails(): void
    {
        $deal = BeloteScorer::score(1, 3, 0, Suit::Hearts, [60, 102], [3, 5], 0, 0);

        self::assertSame(Outcome::Failed, $deal->outcome);
        self::assertSame([20, 162], $deal->scored);
    }

    public function testBeloteCountsToMakeTheContract(): void
    {
        // 76 + 20 = 96 contre 86 : réussi
        $deal = BeloteScorer::score(1, 3, 0, Suit::Hearts, [76, 86], [4, 4], 0, 0);

        self::assertSame(Outcome::Made, $deal->outcome);
        self::assertSame([96, 86], $deal->scored);
    }

    public function testDrawIsALitigeAndTheTakerPointsWait(): void
    {
        $deal = BeloteScorer::score(1, 3, 0, Suit::Hearts, [81, 81], [4, 4], null, 0);

        self::assertSame(Outcome::Litige, $deal->outcome);
        self::assertSame([0, 81], $deal->scored);
        self::assertSame(81, $deal->pendingAfter);
    }

    public function testPendingPointsGoToTheWinnerOfTheNextDeal(): void
    {
        $made = BeloteScorer::score(2, 0, 1, Suit::Spades, [60, 102], [3, 5], null, 81);
        $failed = BeloteScorer::score(2, 0, 1, Suit::Spades, [102, 60], [5, 3], null, 81);

        // Preneur à la place 1 : équipe 1
        self::assertSame([60, 183], $made->scored);
        self::assertSame([243, 0], $failed->scored);
        self::assertSame(0, $made->pendingAfter);
    }

    public function testCapot(): void
    {
        $deal = BeloteScorer::score(1, 3, 0, Suit::Hearts, [162, 0], [8, 0], null, 0);

        self::assertSame(0, $deal->capotTeam);
        self::assertSame([252, 0], $deal->scored);
    }

    public function testCapotAgainstTheTaker(): void
    {
        $deal = BeloteScorer::score(1, 3, 0, Suit::Hearts, [0, 162], [0, 8], 0, 0);

        self::assertSame(Outcome::Failed, $deal->outcome);
        self::assertSame([20, 252], $deal->scored);
    }
}
