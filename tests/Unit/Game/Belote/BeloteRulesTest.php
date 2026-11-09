<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Belote;

use App\Game\Belote\BeloteRules;
use App\Game\Belote\Suit;
use PHPUnit\Framework\TestCase;

final class BeloteRulesTest extends TestCase
{
    use CardsTrait;

    private const array HAND = ['pique-as', 'pique-7', 'coeur-valet', 'coeur-8', 'carreau-roi'];

    public function testAnyCardCanLeadATrick(): void
    {
        self::assertCount(5, BeloteRules::playableCards(self::cs(...self::HAND), [], Suit::Hearts, 0));
    }

    public function testMustFollowTheLedSuit(): void
    {
        $playable = BeloteRules::playableCards(self::cs(...self::HAND), self::trick(3, 'pique-roi'), Suit::Hearts, 0);

        self::assertSame(['pique-7', 'pique-as'], self::codes($playable));
    }

    public function testMustTrumpWithoutTheLedSuit(): void
    {
        $playable = BeloteRules::playableCards(self::cs(...self::HAND), self::trick(3, 'trefle-as'), Suit::Hearts, 0);

        self::assertSame(['coeur-8', 'coeur-valet'], self::codes($playable));
    }

    public function testMustOvertrumpWhenPossible(): void
    {
        $playable = BeloteRules::playableCards(self::cs(...self::HAND), self::trick(2, 'trefle-as', 3, 'coeur-9'), Suit::Hearts, 0);

        // Le 9 d'atout est battu seulement par le Valet
        self::assertSame(['coeur-valet'], self::codes($playable));
    }

    public function testMustUndertrumpWhenUnableToOvertrump(): void
    {
        $hand = self::cs('pique-as', 'coeur-8', 'coeur-dame');
        $playable = BeloteRules::playableCards($hand, self::trick(2, 'trefle-as', 3, 'coeur-valet'), Suit::Hearts, 0);

        self::assertSame(['coeur-8', 'coeur-dame'], self::codes($playable));
    }

    public function testAnyCardWhenThePartnerIsMaster(): void
    {
        // Place 2 (partenaire de 0) gagne le pli : pas d'obligation de couper
        $playable = BeloteRules::playableCards(self::cs(...self::HAND), self::trick(1, 'trefle-7', 2, 'trefle-as', 3, 'trefle-8'), Suit::Hearts, 0);

        self::assertCount(5, $playable);
    }

    public function testMustGoHigherWhenTrumpIsLed(): void
    {
        $hand = self::cs('coeur-valet', 'coeur-8', 'pique-as');
        $playable = BeloteRules::playableCards($hand, self::trick(3, 'coeur-as'), Suit::Hearts, 0);

        self::assertSame(['coeur-valet'], self::codes($playable));
    }

    public function testAnyTrumpWhenTrumpIsLedAndNoneIsHigher(): void
    {
        $hand = self::cs('coeur-7', 'coeur-8', 'pique-as');
        $playable = BeloteRules::playableCards($hand, self::trick(3, 'coeur-valet'), Suit::Hearts, 0);

        self::assertSame(['coeur-7', 'coeur-8'], self::codes($playable));
    }

    public function testFreeDiscardWithoutLedSuitNorTrump(): void
    {
        $hand = self::cs('pique-as', 'carreau-7');

        self::assertCount(2, BeloteRules::playableCards($hand, self::trick(3, 'trefle-as'), Suit::Hearts, 0));
    }

    public function testHighestOfTheLedSuitWins(): void
    {
        // 10 plus fort que le Roi hors atout ; l'As de carreau ne compte pas (pas la couleur demandée)
        self::assertSame(2, BeloteRules::winner(self::trick(0, 'pique-roi', 1, 'carreau-as', 2, 'pique-10', 3, 'pique-valet'), Suit::Hearts));
    }

    public function testAnyTrumpBeatsTheLedSuit(): void
    {
        self::assertSame(1, BeloteRules::winner(self::trick(0, 'pique-as', 1, 'coeur-7', 2, 'pique-10'), Suit::Hearts));
    }

    public function testJackAndNineAreTheBestTrumps(): void
    {
        self::assertSame(3, BeloteRules::winner(self::trick(0, 'coeur-as', 1, 'coeur-9', 2, 'coeur-10', 3, 'coeur-valet'), Suit::Hearts));
    }

    public function testTrickPoints(): void
    {
        // Valet d'atout 20 + 9 d'atout 14 + As 11 + 7 0
        self::assertSame(45, BeloteRules::points(self::trick(0, 'coeur-valet', 1, 'coeur-9', 2, 'pique-as', 3, 'pique-7'), Suit::Hearts));
    }

    public function testPartnersSitFaceToFace(): void
    {
        self::assertSame(BeloteRules::team(0), BeloteRules::team(2));
        self::assertSame(BeloteRules::team(1), BeloteRules::team(3));
        self::assertNotSame(BeloteRules::team(0), BeloteRules::team(1));
    }
}
