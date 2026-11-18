<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Tarot;

use App\Game\Tarot\TarotCard;
use App\Game\Tarot\TarotDeck;
use App\Game\Tarot\TarotException;
use App\Game\Tarot\TarotRules;
use PHPUnit\Framework\TestCase;

/**
 * Cartes et jeu de la carte (règles FFT).
 */
final class TarotRulesTest extends TestCase
{
    use TarotCardsTrait;

    public function testTheDeckHas78CardsWorth91Points(): void
    {
        $deck = TarotDeck::cards();

        self::assertCount(78, $deck);
        self::assertSame(182, TarotRules::halfPoints($deck));
        self::assertSame(3, TarotRules::bouts($deck));
    }

    public function testCodesGoBothWays(): void
    {
        foreach (TarotDeck::cards() as $card) {
            self::assertTrue(TarotCard::fromCode($card->code())->equals($card));
        }

        self::assertSame('coeur-cavalier', self::c('coeur-cavalier')->code());
        self::assertSame(9, self::c('atout-21')->halfPoints());
        self::assertSame(7, self::c('pique-dame')->halfPoints());
    }

    public function testUnknownCodeIsRefused(): void
    {
        $this->expectException(TarotException::class);
        TarotCard::fromCode('atout-22');
    }

    public function testFollowSuitWithoutClimbing(): void
    {
        $hand = self::cs('coeur-2', 'coeur-roi', 'atout-5', 'excuse');

        // À la couleur : fournir (l'Excuse est toujours permise), sans obligation de monter
        self::assertSame(['coeur-2', 'coeur-roi', 'excuse'], self::codes(TarotRules::playableCards($hand, self::trick(0, 'coeur-dame'))));
    }

    public function testWithoutTheSuitTrumpAndOvertrumpOrUndertrump(): void
    {
        $hand = self::cs('atout-5', 'atout-12', 'pique-3');

        self::assertSame(['atout-12', 'atout-5'], self::codes(TarotRules::playableCards($hand, self::trick(0, 'coeur-dame'))));
        // Un atout 8 est posé : surcouper avec le 12
        self::assertSame(['atout-12'], self::codes(TarotRules::playableCards($hand, self::trick(0, 'coeur-dame', 1, 'atout-8'))));
        // Un atout 15 est posé : on pisse avec n'importe quel atout
        self::assertSame(['atout-12', 'atout-5'], self::codes(TarotRules::playableCards($hand, self::trick(0, 'coeur-dame', 1, 'atout-15'))));
    }

    public function testAtTrumpsClimbIfPossible(): void
    {
        $hand = self::cs('atout-5', 'atout-12', 'pique-3');

        self::assertSame(['atout-12'], self::codes(TarotRules::playableCards($hand, self::trick(0, 'atout-7'))));
    }

    public function testExcuseLedTheNextCardGivesTheSuit(): void
    {
        $trick = self::trick(0, 'excuse', 1, 'pique-4');

        self::assertSame(['pique-9'], self::codes(TarotRules::playableCards(self::cs('pique-9', 'coeur-1'), $trick)));
        self::assertSame(1, TarotRules::winner([...$trick, ...self::trick(2, 'pique-2', 3, 'coeur-roi')]));
    }

    public function testWinnerIsTheHighestTrumpOrTheHighestOfTheSuit(): void
    {
        self::assertSame(2, TarotRules::winner(self::trick(0, 'coeur-dame', 1, 'coeur-roi', 2, 'atout-1', 3, 'coeur-cavalier')));
        self::assertSame(1, TarotRules::winner(self::trick(0, 'coeur-dame', 1, 'coeur-roi', 2, 'pique-roi', 3, 'excuse')));
    }

    public function testPointsToMakeDependOnTheBouts(): void
    {
        self::assertSame([56, 51, 41, 36], array_map(TarotRules::required(...), [0, 1, 2, 3]));
    }
}
