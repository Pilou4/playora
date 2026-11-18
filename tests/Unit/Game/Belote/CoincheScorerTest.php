<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Belote;

use App\Game\Belote\CoincheScorer;
use App\Game\Belote\DealResult;
use App\Game\Belote\Outcome;
use App\Game\Belote\Suit;
use PHPUnit\Framework\TestCase;

/**
 * Règles GameDuell de la coinche et de la contrée. Le preneur est la place 0 (équipe 0) dans tous ces cas.
 */
final class CoincheScorerTest extends TestCase
{
    public function testMadeContractEachTeamScoresItsPointsAndTheTakerTheContract(): void
    {
        $deal = $this->score(100, 1, [110, 52]);

        self::assertSame(Outcome::Made, $deal->outcome);
        self::assertSame([210, 52], $deal->scored);
    }

    public function testFailedContractGivesTheDefense162PlusContract(): void
    {
        $deal = $this->score(120, 1, [100, 62]);

        self::assertSame(Outcome::Failed, $deal->outcome);
        self::assertSame([0, 282], $deal->scored);
    }

    public function testBeloteCountsForTheContractAndStaysWithItsTeam(): void
    {
        // 72 points + belote = 92 : le contrat de 90 est rempli
        self::assertSame([182, 90], $this->score(90, 1, [72, 90], belote: 0)->scored);

        // Contrat chuté : la belote reste à l'équipe qui l'a
        self::assertSame([20, 272], $this->score(110, 1, [72, 90], belote: 0)->scored);
    }

    public function testAtLeast82PointsAreNeeded(): void
    {
        self::assertSame(Outcome::Failed, $this->score(80, 1, [80, 82])->outcome);
        self::assertSame(Outcome::Made, $this->score(80, 1, [82, 80])->outcome);
    }

    public function testLitigeEachTeamScoresItsPoints(): void
    {
        $deal = $this->score(80, 1, [81, 81]);

        self::assertSame(Outcome::Litige, $deal->outcome);
        self::assertSame([81, 81], $deal->scored);
    }

    public function testCoincheAndSurcoincheMultiplyTheContract(): void
    {
        // Coinché et rempli : ses points + 2 × le contrat, la défense rien
        self::assertSame([320, 0], $this->score(100, 2, [120, 42])->scored);
        // Surcoinché et chuté : 162 + 4 × le contrat pour la défense
        self::assertSame([0, 562], $this->score(100, 4, [60, 102])->scored);
    }

    public function testCapotNeedsAllTheTricksAndTheLastIsWorth100(): void
    {
        $capot = $this->score(250, 1, [162, 0], tricks: [8, 0]);

        self::assertSame(Outcome::Made, $capot->outcome);
        self::assertSame([502, 0], $capot->scored);
        self::assertSame(Outcome::Failed, $this->score(250, 1, [152, 10], tricks: [7, 1])->outcome);
    }

    public function testUnannouncedCapotIsAlsoWorth252(): void
    {
        self::assertSame([332, 0], $this->score(80, 1, [162, 0], tricks: [8, 0])->scored);
    }

    public function testGeneraleNeedsTheTakerToWinEveryTrickAlone(): void
    {
        self::assertSame([752, 0], $this->score(500, 1, [162, 0], tricks: [8, 0], takerTricks: 8)->scored);
        self::assertSame(Outcome::Failed, $this->score(500, 1, [162, 0], tricks: [8, 0], takerTricks: 7)->outcome);
    }

    public function testAnnouncementsCountForTheContract(): void
    {
        // 80 + tierce 20 = 100 : contrat de 100 rempli
        self::assertSame([200, 82], CoincheScorer::score(1, 3, 0, Suit::Hearts, 100, 1, [80, 82], [4, 4], null, 2, [20, 0])->scored);
        // Chuté : la défense marque 162 + le contrat + les annonces
        self::assertSame([0, 282], CoincheScorer::score(1, 3, 0, Suit::Hearts, 100, 1, [60, 102], [4, 4], null, 2, [20, 0])->scored);
    }

    public function testContractsGoFrom80ToGenerale(): void
    {
        self::assertSame([80, 90, 100, 110, 120, 130, 140, 150, 160, 250, 500], CoincheScorer::contracts());
    }

    /**
     * @param array{int, int} $cardPoints
     * @param array{int, int} $tricks
     */
    private function score(int $contract, int $multiplier, array $cardPoints, ?int $belote = null, array $tricks = [4, 4], int $takerTricks = 2): DealResult
    {
        return CoincheScorer::score(1, 3, 0, Suit::Hearts, $contract, $multiplier, $cardPoints, $tricks, $belote, $takerTricks);
    }
}
