<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\CardSet;
use App\Enum\Game;
use App\Game\Tarot\TarotDeck;
use PHPUnit\Framework\TestCase;

/**
 * Jeux de cartes proposés dans l'admin : ceux du tarot, et leurs 78 images.
 */
final class CardSetTest extends TestCase
{
    private const string PUBLIC = __DIR__.'/../../../public/';

    public function testTarotOffersClassicAndModernCards(): void
    {
        self::assertSame([CardSet::TarotNouveau, CardSet::TarotModerne], CardSet::forGame(Game::Tarot));
        self::assertSame(CardSet::TarotNouveau, CardSet::defaultFor(Game::Tarot));
        self::assertSame('Tarot moderne', CardSet::TarotModerne->label());
        self::assertSame('images/cartes/tarot/moderne/', CardSet::TarotModerne->path());
        self::assertSame('webp', CardSet::TarotModerne->extension());
        self::assertNotContains(CardSet::TarotModerne, CardSet::forGame(Game::Belote));
    }

    public function testEveryTarotCardHasItsImage(): void
    {
        foreach (CardSet::forGame(Game::Tarot) as $set) {
            foreach (TarotDeck::cards() as $card) {
                self::assertFileExists(self::PUBLIC.$set->path().$card->code().'.'.$set->extension());
            }
        }
    }
}
