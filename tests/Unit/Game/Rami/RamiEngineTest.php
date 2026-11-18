<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Rami;

use App\Game\Rami\RamiBot;
use App\Game\Rami\RamiCard;
use App\Game\Rami\RamiEngine;
use App\Game\Rami\RamiException;
use App\Game\Rami\RamiMeld;
use App\Game\Rami\RamiState;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

final class RamiEngineTest extends TestCase
{
    private RamiEngine $engine;

    protected function setUp(): void
    {
        // Graine fixe : la défausse remélangée l'est toujours de la même façon
        $this->engine = new RamiEngine(new Randomizer(new Xoshiro256StarStar(1)));
    }

    public function testTheDealGivesThirteenCardsAndFourteenToTheFirstPlayer(): void
    {
        $state = $this->engine->deal(42, 4, 3);

        self::assertSame([14, 13, 13, 13], array_map('count', $state->hands));
        self::assertCount(108 - 53, $state->stock);
        self::assertSame([], $state->discard);
        self::assertSame(0, $state->turn);
        self::assertSame(RamiState::PLAY, $state->phase);
        // La même graine donne la même donne
        self::assertSame($state->hands, $this->engine->deal(42, 4, 3)->hands);
    }

    public function testATurnIsDrawThenDiscard(): void
    {
        $state = $this->state([['coeur-7-1', 'pique-2-1'], ['trefle-9-1']], stock: ['carreau-5-1'], discard: ['coeur-roi-1']);

        $this->expectRefusal(fn () => $this->engine->discard($state, 0, 'coeur-7-1'), 'Prenez d’abord une carte (pioche ou défausse).');
        $this->expectRefusal(fn () => $this->engine->draw($state, 1), 'Ce n’est pas votre tour.');

        $this->engine->draw($state, 0);
        self::assertContains('carreau-5-1', $state->hands[0]);
        $this->expectRefusal(fn () => $this->engine->draw($state, 0), 'Vous avez déjà pris une carte : posez ou défaussez.');

        $this->engine->discard($state, 0, 'pique-2-1');
        self::assertSame(['coeur-roi-1', 'pique-2-1'], $state->discard);
        self::assertSame(1, $state->turn);
        self::assertSame(RamiState::DRAW, $state->phase);
    }

    public function testTheFirstMeldNeedsFiftyOnePointsAndARun(): void
    {
        $hand = ['coeur-10-1', 'coeur-valet-1', 'coeur-dame-1', 'pique-roi-1', 'coeur-roi-1', 'trefle-roi-1', 'carreau-2-1'];
        $state = $this->state([$hand, ['trefle-9-1']], phase: RamiState::PLAY);

        // 30 points seulement
        $this->expectRefusal(fn () => $this->engine->meld($state, 0, [['coeur-10-1', 'coeur-valet-1', 'coeur-dame-1']]), 'Première pose : il faut au moins 51 points (vous en avez 30).');
        // 30 points mais pas de suite
        $this->expectRefusal(fn () => $this->engine->meld($state, 0, [['pique-roi-1', 'coeur-roi-1', 'trefle-roi-1']]), 'Première pose : il faut au moins 51 points (vous en avez 30).');

        // Suite + brelan = 60 points
        $this->engine->meld($state, 0, [['coeur-10-1', 'coeur-valet-1', 'coeur-dame-1'], ['pique-roi-1', 'coeur-roi-1', 'trefle-roi-1']]);
        self::assertTrue($state->opened[0]);
        self::assertCount(2, $state->melds);
        self::assertSame(['carreau-2-1'], $state->hands[0]);
    }

    public function testFiftyOnePointsWithoutARunAreNotEnough(): void
    {
        $hand = ['pique-roi-1', 'coeur-roi-1', 'trefle-roi-1', 'pique-dame-1', 'coeur-dame-1', 'trefle-dame-1', 'carreau-2-1'];
        $state = $this->state([$hand, ['trefle-9-1']], phase: RamiState::PLAY);

        $this->expectRefusal(fn () => $this->engine->meld($state, 0, [['pique-roi-1', 'coeur-roi-1', 'trefle-roi-1'], ['pique-dame-1', 'coeur-dame-1', 'trefle-dame-1']]), 'Première pose : il faut au moins une suite.');
    }

    public function testLayingOffAndSwappingAJokerNeedAFirstMeld(): void
    {
        $state = $this->state([['coeur-6-1', 'pique-valet-2', 'carreau-3-1'], ['trefle-9-1']], phase: RamiState::PLAY);
        $state->melds[] = ['owner' => 1, 'meld' => RamiMeld::build(['pique-10-1', 'joker-1', 'pique-dame-1'])];
        $state->melds[] = ['owner' => 1, 'meld' => RamiMeld::build(['coeur-7-1', 'coeur-8-1', 'coeur-9-1'])];

        $this->expectRefusal(fn () => $this->engine->layOff($state, 0, 'coeur-6-1', 1), 'Faites d’abord votre première pose (51 points, dont une suite).');

        $state->opened[0] = true;
        $this->engine->layOff($state, 0, 'coeur-6-1', 1);
        self::assertSame(['coeur-6-1', 'coeur-7-1', 'coeur-8-1', 'coeur-9-1'], $state->melds[1]['meld']->codes());
        $this->expectRefusal(fn () => $this->engine->layOff($state, 0, 'carreau-3-1', 1), 'Cette carte ne complète pas cette combinaison.');

        // Le valet de pique prend la place du joker, qui revient dans la main
        $this->engine->swapJoker($state, 0, 'pique-valet-2', 0);
        self::assertSame(['pique-10-1', 'pique-valet-2', 'pique-dame-1'], $state->melds[0]['meld']->codes());
        self::assertContains('joker-1', $state->hands[0]);
        $this->expectRefusal(fn () => $this->engine->swapJoker($state, 0, 'carreau-3-1', 0), 'Aucun joker de cette combinaison ne remplace cette carte.');
    }

    public function testACardTakenFromTheDiscardMustBeUsedOrGivenBack(): void
    {
        $state = $this->state([['coeur-7-1', 'coeur-8-1', 'pique-2-1'], ['trefle-9-1']], stock: ['carreau-5-1'], discard: ['trefle-roi-1']);
        $state->opened[0] = true;

        $this->engine->takeDiscard($state, 0);
        self::assertSame('trefle-roi-1', $state->taken);
        $this->expectRefusal(fn () => $this->engine->discard($state, 0, 'pique-2-1'), 'Posez d’abord la carte prise dans la défausse, ou rendez-la.');

        $this->engine->giveBack($state, 0);
        self::assertSame(['trefle-roi-1'], $state->discard);
        self::assertSame(RamiState::DRAW, $state->phase);

        // Prise puis posée : la défausse redevient possible
        $state->discard[] = 'coeur-9-1';
        $this->engine->takeDiscard($state, 0);
        $this->engine->meld($state, 0, [['coeur-7-1', 'coeur-8-1', 'coeur-9-1']]);
        self::assertNull($state->taken);
        $this->engine->discard($state, 0, 'pique-2-1');
        self::assertTrue($state->isOver());
    }

    public function testTheRoundEndsWithPenaltiesAndARamiSecDoublesThem(): void
    {
        // Déjà posé avant ce tour : pénalités simples
        $state = $this->state([['pique-2-1'], ['joker-1', 'coeur-as-1', 'trefle-roi-1']], phase: RamiState::PLAY);
        $state->opened[0] = true;
        $state->openedBefore = true;
        $this->engine->discard($state, 0, 'pique-2-1');

        self::assertSame(0, $state->winner);
        self::assertFalse($state->ramiSec);
        self::assertSame([0, 41], $state->penalties);

        // Tout posé d'un coup : rami sec, pénalités doublées
        $state = $this->state([['coeur-10-1', 'coeur-valet-1', 'coeur-dame-1', 'coeur-roi-1', 'pique-as-1', 'trefle-as-1', 'carreau-as-1'], ['pique-9-1']], phase: RamiState::PLAY);
        $this->engine->meld($state, 0, [['coeur-10-1', 'coeur-valet-1', 'coeur-dame-1', 'coeur-roi-1'], ['pique-as-1', 'trefle-as-1', 'carreau-as-1']]);

        self::assertTrue($state->isOver());
        self::assertTrue($state->ramiSec);
        self::assertSame([0, 18], $state->penalties);
    }

    public function testAnEmptyStockIsRefilledFromTheDiscard(): void
    {
        $state = $this->state([['coeur-7-1'], ['trefle-9-1']], discard: ['pique-2-1', 'pique-3-1', 'pique-4-1']);

        $this->engine->draw($state, 0);

        // La carte du dessus reste sur la défausse, les autres sont remélangées dans la pioche
        self::assertSame(['pique-4-1'], $state->discard);
        self::assertCount(1, $state->stock);
        self::assertEqualsCanonicalizing(['pique-2-1', 'pique-3-1'], [$state->stock[0], ...array_slice($state->hands[0], 1)]);
    }

    public function testTimeoutPlaysASimpleTurn(): void
    {
        $state = $this->state([['coeur-7-1', 'coeur-8-1', 'joker-1'], ['trefle-9-1']], stock: ['pique-roi-1'], discard: ['carreau-2-1']);
        $this->engine->takeDiscard($state, 0);

        $this->engine->autoPlay($state);

        // La carte prise est rendue, il pioche puis défausse le Roi isolé (jamais le joker)
        self::assertSame(['carreau-2-1', 'pique-roi-1'], $state->discard);
        self::assertSame(1, $state->turn);
        self::assertContains('joker-1', $state->hands[0]);
    }

    public function testRobotsPlayWholeRoundsAndNoCardIsLost(): void
    {
        $bot = new RamiBot($this->engine);

        foreach (range(1, 30) as $seed) {
            $players = 2 + $seed % 5;
            $state = $this->engine->deal($seed, $players, 0);

            for ($turn = 0; $turn < 2000 && !$state->isOver(); ++$turn) {
                $bot->play($state);
            }

            self::assertTrue($state->isOver(), 'Manche sans fin, graine '.$seed);
            self::assertSame(108, \count($state->stock) + \count($state->discard) + array_sum(array_map('count', $state->hands)) + array_sum(array_map(static fn (array $entry): int => \count($entry['meld']->codes()), $state->melds)));
            self::assertSame($state->toArray(), RamiState::fromArray($state->toArray())->toArray());
        }
    }

    /**
     * @param list<list<string>> $hands
     * @param list<string>       $stock
     * @param list<string>       $discard
     */
    private function state(array $hands, array $stock = [], array $discard = [], string $phase = RamiState::DRAW): RamiState
    {
        $state = new RamiState(\count($hands), \count($hands) - 1);
        $state->hands = $hands;
        $state->stock = $stock;
        $state->discard = $discard;
        $state->phase = $phase;

        return $state;
    }

    private function expectRefusal(\Closure $action, string $message): void
    {
        try {
            $action();
            self::fail('Coup accepté : '.$message);
        } catch (RamiException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
