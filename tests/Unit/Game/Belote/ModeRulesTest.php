<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Belote;

use App\Game\Belote\BeloteBot;
use App\Game\Belote\BeloteEngine;
use App\Game\Belote\BeloteException;
use App\Game\Belote\Card;
use App\Game\Belote\Deck;
use App\Game\Belote\ModeRules;
use App\Game\Belote\Phase;
use App\Game\Belote\TrumpMode;
use App\Game\Belote\Variant;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Coinche en tout atout (TA) et en sans atout (SA), règles GameDuell.
 */
final class ModeRulesTest extends TestCase
{
    use CardsTrait;

    public function testEachModeIsWorth152PointsInTheCards(): void
    {
        foreach ([TrumpMode::AllTrumps, TrumpMode::NoTrumps] as $mode) {
            $total = array_sum(array_map(static fn (Card $card): int => ModeRules::points($card, $mode), Deck::shuffled(new Randomizer())));

            self::assertSame(152, $total, $mode->label());
        }
    }

    public function testCardValues(): void
    {
        self::assertSame(14, ModeRules::points(self::c('coeur-valet'), TrumpMode::AllTrumps));
        self::assertSame(9, ModeRules::points(self::c('pique-9'), TrumpMode::AllTrumps));
        self::assertSame(19, ModeRules::points(self::c('trefle-as'), TrumpMode::NoTrumps));
        self::assertSame(2, ModeRules::points(self::c('trefle-valet'), TrumpMode::NoTrumps));
    }

    public function testHighestCardOfTheLedSuitWins(): void
    {
        $trick = self::trick(0, 'coeur-as', 1, 'coeur-valet', 2, 'pique-valet', 3, 'coeur-10');

        // Tout atout : le Valet ; sans atout : l'As. Le Valet de pique ne coupe pas.
        self::assertSame(1, ModeRules::winner($trick, TrumpMode::AllTrumps));
        self::assertSame(0, ModeRules::winner($trick, TrumpMode::NoTrumps));
    }

    public function testFollowSuitAndClimbOnlyInAllTrumps(): void
    {
        $hand = self::cs('coeur-7', 'coeur-9', 'pique-as');
        $trick = self::trick(0, 'coeur-as');

        self::assertSame(['coeur-9'], self::codes(ModeRules::playableCards($hand, $trick, TrumpMode::AllTrumps)));
        self::assertSame(['coeur-7', 'coeur-9'], self::codes(ModeRules::playableCards($hand, $trick, TrumpMode::NoTrumps)));
        // Sans la couleur : n'importe quelle carte
        self::assertCount(3, ModeRules::playableCards($hand, self::trick(0, 'carreau-7'), TrumpMode::AllTrumps));
    }

    public function testEveryKingAndQueenOfASuitIsABeloteInAllTrumps(): void
    {
        $engine = new BeloteEngine(new Randomizer(new Xoshiro256StarStar(3)));
        [$state] = $engine->start(701, 0, Variant::Coinche);
        $state->hands[1] = self::cs('coeur-roi', 'coeur-dame', 'pique-roi', 'pique-dame', 'carreau-7', 'carreau-8', 'trefle-7', 'trefle-8');
        $state->hands[2] = self::cs('trefle-roi', 'trefle-dame', 'coeur-7', 'coeur-8', 'pique-7', 'pique-8', 'carreau-dame', 'carreau-10');
        $state->hands[0] = self::cs('coeur-9', 'coeur-10', 'coeur-valet', 'coeur-as', 'pique-9', 'pique-10', 'pique-valet', 'pique-as');
        $state->hands[3] = self::cs('carreau-9', 'carreau-valet', 'carreau-roi', 'carreau-as', 'trefle-9', 'trefle-10', 'trefle-valet', 'trefle-as');
        $noTrumps = clone $state;
        $engine->bid($state, 1, 100, null, TrumpMode::AllTrumps);
        $engine->pass($state, 2);
        $engine->pass($state, 3);
        $engine->pass($state, 0);

        self::assertSame(Phase::Playing, $state->phase);
        // Place 1 (équipe 1) : 2 belotes ; place 2 (équipe 0) : 1 belote
        self::assertSame([20, 40], $state->belotePoints());

        // Sans atout : pas de belote
        $engine->bid($noTrumps, 1, 100, null, TrumpMode::NoTrumps);
        $engine->pass($noTrumps, 2);
        $engine->pass($noTrumps, 3);
        $engine->pass($noTrumps, 0);
        self::assertSame([0, 0], $noTrumps->belotePoints());
    }

    public function testBeloteAlwaysCountsEvenWhenTheContractFails(): void
    {
        // Tout atout, preneur (équipe 0) chuté avec 2 belotes : 40 pour lui, 162 + 100 + 20 pour la défense
        $deal = \App\Game\Belote\CoincheScorer::score(1, 3, 0, null, 100, 1, [50, 112], [3, 5], null, 2, [0, 0], TrumpMode::AllTrumps, [40, 20]);

        self::assertSame([40, 282], $deal->scored);
    }

    public function testOnlyTheCoincheAllowsAllTrumpsAndNoTrumps(): void
    {
        $engine = new BeloteEngine(new Randomizer(new Xoshiro256StarStar(3)));
        [$coinche] = $engine->start(701, 0, Variant::Coinche);
        $engine->bid($coinche, 1, 100, null, TrumpMode::AllTrumps);

        self::assertSame(TrumpMode::AllTrumps, $coinche->mode);
        self::assertNull($coinche->trump);

        [$contree] = $engine->start(701, 0, Variant::Contree);
        $this->expectException(BeloteException::class);
        $engine->bid($contree, 1, 100, null, TrumpMode::NoTrumps);
    }

    public function testASuitIsNeededForAColourContract(): void
    {
        $engine = new BeloteEngine(new Randomizer(new Xoshiro256StarStar(3)));
        [$state] = $engine->start(701, 0, Variant::Coinche);

        $this->expectException(BeloteException::class);
        $engine->bid($state, 1, 100, null);
    }

    public function testWholeGamesInAllTrumpsAndNoTrumps(): void
    {
        foreach ([TrumpMode::AllTrumps, TrumpMode::NoTrumps] as $mode) {
            $engine = new BeloteEngine(new Randomizer(new Xoshiro256StarStar(11)));
            $bot = new BeloteBot($engine);
            [$state] = $engine->start(701, 0, Variant::Coinche);
            $deals = 0;

            for ($actions = 0; Phase::Finished !== $state->phase; ++$actions) {
                self::assertLessThan(20000, $actions, 'La partie doit se terminer.');

                // La place 0 annonce toujours 80 dans ce mode quand personne n'a encore parlé
                $result = Phase::Auction === $state->phase && 0 === $state->turn && null === $state->contract
                    ? $engine->bid($state, 0, 80, null, $mode)
                    : $bot->act($state);

                foreach ($result->deals as $deal) {
                    $deals += $mode === $deal->mode ? 1 : 0;
                    self::assertSame(162 + ($deal->capotTeam === null ? 0 : 90), array_sum($deal->cardPoints), 'Une donne vaut 162 points.');
                }
            }

            self::assertGreaterThan(0, $deals, $mode->label());
        }
    }
}
