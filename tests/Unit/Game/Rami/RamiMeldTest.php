<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Rami;

use App\Game\Rami\RamiCard;
use App\Game\Rami\RamiException;
use App\Game\Rami\RamiMeld;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RamiMeldTest extends TestCase
{
    public function testARunIsSortedAndCounted(): void
    {
        $meld = RamiMeld::build(['coeur-9-1', 'coeur-7-2', 'coeur-8-1']);

        self::assertSame(RamiMeld::RUN, $meld->kind);
        self::assertSame(['coeur-7-2', 'coeur-8-1', 'coeur-9-1'], $meld->codes());
        self::assertSame(24, $meld->points);
    }

    public function testASetOfDifferentSuits(): void
    {
        $meld = RamiMeld::build(['pique-roi-1', 'coeur-roi-2', 'trefle-roi-1', 'carreau-roi-1']);

        self::assertSame(RamiMeld::SET, $meld->kind);
        self::assertSame(40, $meld->points);
    }

    public function testAJokerFillsAGapAndTakesItsValue(): void
    {
        $meld = RamiMeld::build(['pique-10-1', 'joker-1', 'pique-dame-1']);

        self::assertSame(['pique-10-1', 'joker-1', 'pique-dame-1'], $meld->codes());
        self::assertSame('pique-valet', $meld->cards[1]['face']);
        self::assertSame(30, $meld->points);
    }

    public function testASpareJokerExtendsTheRunUpwardsThenDownwards(): void
    {
        self::assertSame('coeur-10', RamiMeld::build(['coeur-8-1', 'coeur-9-1', 'joker-2'])->cards[2]['face']);
        // Rien au-dessus de l'As (après le Roi) : le joker passe en dessous
        self::assertSame('coeur-dame', RamiMeld::build(['coeur-roi-1', 'coeur-as-1', 'joker-2'])->cards[0]['face']);
    }

    public function testTheAceIsLowOrHighButNeverBoth(): void
    {
        self::assertSame(6, RamiMeld::build(['trefle-as-1', 'trefle-2-1', 'trefle-3-1'])->points);
        self::assertSame(31, RamiMeld::build(['trefle-dame-1', 'trefle-roi-1', 'trefle-as-1'])->points);

        $this->expectException(RamiException::class);
        RamiMeld::build(['trefle-roi-1', 'trefle-as-1', 'trefle-2-1']);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidMelds(): iterable
    {
        yield 'deux cartes' => [['coeur-7-1', 'coeur-8-1']];
        yield 'couleurs mêlées' => [['coeur-7-1', 'pique-8-1', 'coeur-9-1']];
        yield 'trou sans joker' => [['coeur-7-1', 'coeur-9-1', 'coeur-10-1']];
        yield 'brelan avec deux fois la même couleur' => [['coeur-7-1', 'coeur-7-2', 'pique-7-1']];
        yield 'série de cinq' => [['coeur-7-1', 'pique-7-1', 'trefle-7-1', 'carreau-7-1', 'joker-1']];
        yield 'une seule vraie carte' => [['coeur-7-1', 'joker-1', 'joker-2']];
        yield 'carte inconnue' => [['coeur-7-3', 'coeur-8-1', 'coeur-9-1']];
    }

    /**
     * @param list<string> $codes
     */
    #[DataProvider('invalidMelds')]
    public function testInvalidMeldsAreRefused(array $codes): void
    {
        $this->expectException(RamiException::class);
        RamiMeld::build($codes);
    }

    public function testTheCardAJokerStandsFor(): void
    {
        $run = RamiMeld::build(['pique-10-1', 'joker-1', 'pique-dame-1']);
        $set = RamiMeld::build(['pique-7-1', 'coeur-7-1', 'joker-3']);

        self::assertSame('joker-1', $run->jokerFor(RamiCard::fromCode('pique-valet-2')));
        self::assertNull($run->jokerFor(RamiCard::fromCode('coeur-valet-1')));
        self::assertSame('joker-3', $set->jokerFor(RamiCard::fromCode('trefle-7-2')));
        self::assertNull($set->jokerFor(RamiCard::fromCode('coeur-7-2')));
    }

    public function testRoundTrip(): void
    {
        $meld = RamiMeld::build(['coeur-9-1', 'joker-4', 'coeur-7-1']);

        self::assertEquals($meld, RamiMeld::fromArray($meld->toArray()));
    }

    public function testTheDeckHas108CardsAndPenalties(): void
    {
        $codes = array_map(static fn (RamiCard $card): string => $card->code(), RamiCard::deck());

        self::assertCount(108, $codes);
        self::assertCount(108, array_unique($codes));
        self::assertSame(20, RamiCard::fromCode('joker-1')->penalty());
        self::assertSame(11, RamiCard::fromCode('coeur-as-1')->penalty());
        self::assertSame(10, RamiCard::fromCode('coeur-dame-1')->penalty());
        self::assertSame(7, RamiCard::fromCode('coeur-7-1')->penalty());
    }
}
