<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Tarot;

use App\Game\Tarot\Poignee;
use App\Game\Tarot\TarotBot;
use App\Game\Tarot\TarotContract;
use App\Game\Tarot\TarotEngine;
use App\Game\Tarot\TarotException;
use App\Game\Tarot\TarotPhase;
use App\Game\Tarot\TarotState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Déroulé d'une partie : donne, enchères, chien et écart, poignée, chelem, fin de partie.
 */
final class TarotEngineTest extends TestCase
{
    use TarotCardsTrait;

    public function testEachPlayerGets18CardsAndTheDogHas6(): void
    {
        [$state] = $this->start();

        self::assertSame([18, 18, 18, 18], array_map('count', $state->hands));
        self::assertCount(6, $state->dog);
        self::assertSame(TarotPhase::Bidding, $state->phase);
        self::assertSame(1, $state->turn);
    }

    public function testEachBidMustBeHigher(): void
    {
        [$state, $engine] = $this->start();
        $engine->bid($state, 1, TarotContract::Garde);

        $this->expectException(TarotException::class);
        $engine->bid($state, 2, TarotContract::Petite);
    }

    public function testNobodyTakesTheNextDealerDealsAgain(): void
    {
        [$state, $engine] = $this->start();

        foreach ([1, 2, 3, 0] as $position) {
            $result = $engine->bid($state, $position, null);
        }

        self::assertSame(1, $state->dealer);
        self::assertSame(1, $state->dealNumber);
        self::assertSame('redeal', $result->events[1]['type']);
    }

    public function testGardeTheTakerTakesTheDogAndDiscards(): void
    {
        [$state, $engine] = $this->start();
        $engine->bid($state, 1, TarotContract::Garde);

        foreach ([2, 3, 0] as $position) {
            $result = $engine->bid($state, $position, null);
        }

        self::assertSame(TarotPhase::Dog, $state->phase);
        self::assertCount(6, $result->events[1]['dog']);
        self::assertCount(24, $state->hands[1]);

        $engine->discard($state, 1, TarotBot::chooseDiscard($state->hands[1]));

        self::assertCount(18, $state->hands[1]);
        self::assertCount(6, $state->discard);
        self::assertSame(TarotPhase::Playing, $state->phase);
    }

    public function testNoKingNorBoutInTheDiscard(): void
    {
        [$state, $engine] = $this->dogPhase();
        $state->hands[1] = self::cs(
            'coeur-roi', 'coeur-2', 'coeur-3', 'coeur-4', 'coeur-5', 'coeur-6', 'coeur-7', 'coeur-8',
            'pique-2', 'pique-3', 'pique-4', 'pique-5', 'pique-6', 'pique-7', 'pique-8', 'pique-9',
            'atout-1', 'atout-2', 'atout-3', 'atout-4', 'atout-5', 'atout-6', 'atout-7', 'atout-8',
        );

        $this->expectException(TarotException::class);
        $this->expectExceptionMessage('ni Roi ni bout');
        $engine->discard($state, 1, self::cs('coeur-roi', 'coeur-2', 'coeur-3', 'coeur-4', 'coeur-5', 'coeur-6'));
    }

    public function testTrumpsInTheDiscardOnlyIfNeeded(): void
    {
        [$state, $engine] = $this->dogPhase();
        $state->hands[1] = self::cs(
            'coeur-2', 'coeur-3', 'coeur-4', 'coeur-5', 'coeur-6', 'coeur-7', 'atout-2', 'atout-3',
            'atout-4', 'atout-5', 'atout-6', 'atout-7', 'atout-8', 'atout-9', 'atout-10', 'atout-11',
            'atout-12', 'atout-13', 'atout-14', 'atout-15', 'atout-16', 'atout-17', 'atout-18', 'atout-19',
        );

        $this->expectException(TarotException::class);
        $engine->discard($state, 1, self::cs('coeur-2', 'coeur-3', 'coeur-4', 'coeur-5', 'coeur-6', 'atout-2'));
    }

    public function testShownTrumpsWhenTheyAreNeeded(): void
    {
        [$state, $engine] = $this->dogPhase();
        $state->hands[1] = self::cs(
            'coeur-2', 'coeur-3', 'coeur-4', 'coeur-roi', 'pique-roi', 'atout-2', 'atout-3', 'atout-4',
            'atout-5', 'atout-6', 'atout-7', 'atout-8', 'atout-9', 'atout-10', 'atout-11', 'atout-12',
            'atout-13', 'atout-14', 'atout-15', 'atout-16', 'atout-17', 'atout-18', 'atout-19', 'atout-20',
        );

        $result = $engine->discard($state, 1, self::cs('coeur-2', 'coeur-3', 'coeur-4', 'atout-2', 'atout-3', 'atout-4'));

        self::assertSame(['atout-2', 'atout-3', 'atout-4'], $result->events[0]['trumps']);
    }

    public function testGardeSansTheDogStaysHidden(): void
    {
        [$state, $engine] = $this->start();
        $engine->bid($state, 1, TarotContract::GardeSans);

        foreach ([2, 3, 0] as $position) {
            $result = $engine->bid($state, $position, null);
        }

        self::assertArrayNotHasKey('dog', $result->events[1]);
        self::assertCount(6, $state->discard);
        self::assertSame(TarotPhase::Playing, $state->phase);
    }

    public function testPoigneeBeforeTheFirstCard(): void
    {
        [$state, $engine] = $this->start();
        $state->phase = TarotPhase::Playing;
        $state->turn = 2;
        $state->hands[2] = self::cs(
            'atout-1', 'atout-2', 'atout-3', 'atout-4', 'atout-5', 'atout-6', 'atout-7', 'atout-8', 'atout-9',
            'excuse', 'coeur-2', 'coeur-3', 'coeur-4', 'coeur-5', 'coeur-6', 'coeur-7', 'coeur-8', 'coeur-9',
        );

        self::assertSame([Poignee::Simple], $engine->poigneesFor($state, 2));
        $result = $engine->poignee($state, 2, Poignee::Simple);

        // 9 atouts et l'Excuse sont montrés
        self::assertCount(10, $result->events[0]['cards']);
        self::assertContains('excuse', $result->events[0]['cards']);
        self::assertSame([], $engine->poigneesFor($state, 2));
    }

    public function testChelemAnnouncedTheTakerLeads(): void
    {
        [$state, $engine] = $this->start();
        $engine->bid($state, 1, TarotContract::GardeContre);

        foreach ([2, 3, 0] as $position) {
            $engine->bid($state, $position, null);
        }

        $engine->chelem($state, 1);

        self::assertTrue($state->chelemAnnounced);
        self::assertSame(1, $state->turn);
    }

    public function testStateSurvivesTheSaving(): void
    {
        [$state, $engine] = $this->start();
        $bot = new TarotBot($engine);

        for ($i = 0; $i < 40; ++$i) {
            $bot->act($state);
        }

        $copy = TarotState::fromArray(json_decode(json_encode($state->toArray(), \JSON_THROW_ON_ERROR), true));

        self::assertSame($state->toArray(), $copy->toArray());
    }

    public function testThreePlayersGet24CardsAndFiveGet15WithADogOf3(): void
    {
        $engine = new TarotEngine(new Randomizer(new Xoshiro256StarStar(5)));
        [$three] = $engine->start(4, 0, 3);
        [$five] = $engine->start(4, 0, 5);

        self::assertSame([24, 24, 24], array_map('count', $three->hands));
        self::assertCount(6, $three->dog);
        self::assertSame([15, 15, 15, 15, 15], array_map('count', $five->hands));
        self::assertCount(3, $five->dog);
    }

    public function testFivePlayersTheTakerCallsAKingAndItsHolderIsHisPartner(): void
    {
        $engine = new TarotEngine(new Randomizer(new Xoshiro256StarStar(5)));
        [$state] = $engine->start(4, 0, 5);
        $state->hands[1] = [...\array_slice($state->hands[1], 0, 14), self::c('coeur-1')];
        $state->hands[3] = [...array_values(array_filter($state->hands[3], static fn ($card): bool => 'coeur-roi' !== $card->code())), self::c('coeur-roi')];
        $state->hands[3] = \array_slice($state->hands[3], -15);
        $engine->bid($state, 1, TarotContract::GardeSans);

        foreach ([2, 3, 4, 0] as $position) {
            $engine->bid($state, $position, null);
        }

        self::assertSame(TarotPhase::Call, $state->phase);
        self::assertCount(4, $engine->callable($state));

        $result = $engine->call($state, 1, self::c('coeur-roi'));

        self::assertSame('called', $result->events[0]['type']);
        self::assertSame(3, $state->partner);
        self::assertSame(0, $state->camp(3));
        // Les autres ne le savent pas encore ; le partenaire, lui, le sait
        self::assertTrue($state->seenAsAlly(2, 3));
        self::assertTrue($state->seenAsAlly(3, 1));
        self::assertFalse($state->seenAsAlly(1, 3));
    }

    public function testPoigneeNeedsFewerTrumpsAtFive(): void
    {
        self::assertSame([13, 10, 8], [Poignee::Simple->trumps(3), Poignee::Simple->trumps(4), Poignee::Simple->trumps(5)]);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function seeds(): iterable
    {
        foreach ([3, 4, 5] as $players) {
            foreach ([1, 2, 3] as $seed) {
                yield $players.' joueurs, partie '.$seed => [$players, $seed];
            }
        }
    }

    #[DataProvider('seeds')]
    public function testBotsPlayAWholeGame(int $players, int $seed): void
    {
        $engine = new TarotEngine(new Randomizer(new Xoshiro256StarStar($seed)));
        $bot = new TarotBot($engine);
        [$state] = $engine->start(4, 0, $players);
        $deals = 0;

        for ($actions = 0; TarotPhase::Finished !== $state->phase; ++$actions) {
            self::assertLessThan(5000, $actions, 'La partie doit se terminer.');

            foreach ($bot->act($state)->deals as $deal) {
                ++$deals;
                self::assertSame(0, array_sum($deal->scored));
            }
        }

        self::assertSame(4, $deals);
        self::assertSame(0, array_sum($state->scores));
        self::assertNotNull($state->winner);
    }

    /**
     * @return array{TarotState, TarotEngine}
     */
    private function start(): array
    {
        $engine = new TarotEngine(new Randomizer(new Xoshiro256StarStar(42)));
        [$state] = $engine->start(4, 0);

        return [$state, $engine];
    }

    /**
     * @return array{TarotState, TarotEngine}
     */
    private function dogPhase(): array
    {
        [$state, $engine] = $this->start();
        $state->phase = TarotPhase::Dog;
        $state->taker = 1;
        $state->contract = TarotContract::Garde;
        $state->turn = 1;

        return [$state, $engine];
    }
}
