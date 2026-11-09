<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Belote;

use App\Game\Belote\BeloteException;
use App\Game\Belote\Card;
use App\Game\Belote\Rank;
use App\Game\Belote\Suit;
use PHPUnit\Framework\TestCase;

final class CardTest extends TestCase
{
    public function testCodeRoundTrip(): void
    {
        $card = Card::fromCode('coeur-valet');

        self::assertSame(Suit::Hearts, $card->suit);
        self::assertSame(Rank::Jack, $card->rank);
        self::assertSame('coeur-valet', $card->code());
    }

    public function testUnknownCodeIsRefused(): void
    {
        $this->expectException(BeloteException::class);

        Card::fromCode('coeur-joker');
    }

    public function testPointsDependOnTheTrump(): void
    {
        $jack = Card::fromCode('pique-valet');

        self::assertSame(20, $jack->points(Suit::Spades));
        self::assertSame(2, $jack->points(Suit::Hearts));
    }

    public function testAllCardsAddUpTo152(): void
    {
        $total = 0;

        foreach (Suit::cases() as $suit) {
            foreach (Rank::cases() as $rank) {
                $total += (new Card($suit, $rank))->points(Suit::Hearts);
            }
        }

        // 152 + dix de der = 162
        self::assertSame(152, $total);
    }
}
