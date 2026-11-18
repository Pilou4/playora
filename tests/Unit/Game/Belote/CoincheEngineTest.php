<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Belote;

use App\Game\Belote\BeloteBot;
use App\Game\Belote\BeloteEngine;
use App\Game\Belote\BeloteException;
use App\Game\Belote\BeloteState;
use App\Game\Belote\Phase;
use App\Game\Belote\Suit;
use App\Game\Belote\Variant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Coinche et contrée : donne de 8 cartes, enchères de 80 à 160 (ou capot), coinche et surcoinche.
 */
final class CoincheEngineTest extends TestCase
{
    public function testEachPlayerGetsEightCardsAndNoCardIsTurned(): void
    {
        [$state] = $this->start();

        self::assertSame(Phase::Auction, $state->phase);
        self::assertNull($state->turnedCard);
        self::assertSame([8, 8, 8, 8], array_map('count', $state->hands));
        self::assertSame([], $state->stock);
        // Le joueur à gauche du donneur parle en premier
        self::assertSame(1, $state->turn);
    }

    public function testEveryBidMustBeHigher(): void
    {
        [$state, $engine] = $this->start();
        $engine->bid($state, 1, 90, Suit::Hearts);

        $this->expectException(BeloteException::class);
        $this->expectExceptionMessage('Annoncez plus que 90');
        $engine->bid($state, 2, 90, Suit::Spades);
    }

    public function testOnlyRoundContractsOrCapot(): void
    {
        [$state, $engine] = $this->start();

        $this->expectException(BeloteException::class);
        $engine->bid($state, 1, 85, Suit::Hearts);
    }

    public function testThreePassesAfterABidStartThePlay(): void
    {
        [$state, $engine] = $this->start();
        $engine->bid($state, 1, 100, Suit::Clubs);
        $engine->pass($state, 2);
        $engine->pass($state, 3);
        $result = $engine->pass($state, 0);

        self::assertSame(Phase::Playing, $state->phase);
        self::assertSame(1, $state->taker);
        self::assertSame(Suit::Clubs, $state->trump);
        self::assertSame(100, $state->contract);
        self::assertSame(1, $state->turn);
        self::assertSame('contract', $result->events[array_key_last($result->events)]['type']);
    }

    public function testFourPassesRedeal(): void
    {
        [$state, $engine] = $this->start();

        foreach ([1, 2, 3, 0] as $position) {
            $engine->pass($state, $position);
        }

        self::assertSame(Phase::Auction, $state->phase);
        self::assertSame(2, $state->dealNumber);
        self::assertSame(1, $state->dealer);
    }

    public function testOnlyTheOpponentsCanCoinche(): void
    {
        [$state, $engine] = $this->start();
        $engine->bid($state, 1, 100, Suit::Clubs);
        $engine->pass($state, 2);

        $this->expectException(BeloteException::class);
        $engine->coinche($state, 3);
    }

    public function testCoincheStopsTheBiddingAndSurcoincheStartsThePlay(): void
    {
        [$state, $engine] = $this->start();
        $engine->bid($state, 1, 110, Suit::Spades);
        $engine->coinche($state, 2);

        self::assertSame(2, $state->multiplier);

        try {
            $engine->bid($state, 3, 120, Suit::Hearts);
            self::fail('On ne monte plus après une coinche.');
        } catch (BeloteException) {
        }

        $engine->surcoinche($state, 3);

        self::assertSame(4, $state->multiplier);
        self::assertSame(Phase::Playing, $state->phase);
        self::assertSame(1, $state->taker);
    }

    public function testCoinchedContractIsPlayedAfterThreePasses(): void
    {
        [$state, $engine] = $this->start();
        $engine->bid($state, 1, 110, Suit::Spades);
        $engine->coinche($state, 2);
        $engine->pass($state, 3);
        $engine->pass($state, 0);
        $engine->pass($state, 1);

        self::assertSame(Phase::Playing, $state->phase);
        self::assertSame(2, $state->multiplier);
    }

    public function testClassicActionsAreRefused(): void
    {
        [$state, $engine] = $this->start();

        $this->expectException(BeloteException::class);
        $engine->take($state, 1, Suit::Hearts);
    }

    public function testStateKeepsTheAuctionWhenSaved(): void
    {
        [$state, $engine] = $this->start();
        $engine->bid($state, 1, 120, Suit::Diamonds);
        $engine->coinche($state, 2);

        $copy = BeloteState::fromArray(json_decode(json_encode($state->toArray(), \JSON_THROW_ON_ERROR), true));

        self::assertSame(Variant::Coinche, $copy->variant);
        self::assertSame(120, $copy->contract);
        self::assertSame(2, $copy->multiplier);
        self::assertSame(2, $copy->coincher);
        self::assertSame([1 => 120], $copy->bids);
    }

    /**
     * @return iterable<string, array{Variant, int}>
     */
    public static function games(): iterable
    {
        foreach ([1, 2, 3] as $seed) {
            yield 'coinche '.$seed => [Variant::Coinche, $seed];
            yield 'contrée '.$seed => [Variant::Contree, $seed];
        }
    }

    #[DataProvider('games')]
    public function testFourBotsPlayAWholeGame(Variant $variant, int $seed): void
    {
        $engine = new BeloteEngine(new Randomizer(new Xoshiro256StarStar($seed)));
        $bot = new BeloteBot($engine);
        [$state] = $engine->start(1000, 0, $variant);

        for ($actions = 0; Phase::Finished !== $state->phase; ++$actions) {
            self::assertLessThan(20000, $actions, 'La partie doit se terminer.');
            $bot->act($state);
        }

        self::assertGreaterThanOrEqual(1000, max($state->scores));
        self::assertNotSame($state->scores[0], $state->scores[1]);
    }

    /**
     * @return array{BeloteState, BeloteEngine}
     */
    private function start(): array
    {
        $engine = new BeloteEngine(new Randomizer(new Xoshiro256StarStar(42)));
        [$state] = $engine->start(1000, 0, Variant::Coinche);

        return [$state, $engine];
    }
}
