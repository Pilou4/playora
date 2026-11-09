<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Belote;

use App\Game\Belote\Card;
use App\Game\Belote\TrickCard;

/**
 * Écriture courte des cartes dans les tests : c('coeur-valet'), cs('pique-as', 'pique-7').
 */
trait CardsTrait
{
    private static function c(string $code): Card
    {
        return Card::fromCode($code);
    }

    /**
     * @return list<Card>
     */
    private static function cs(string ...$codes): array
    {
        return array_map(Card::fromCode(...), $codes);
    }

    /**
     * Pli : alternance place, carte. trick(0, 'pique-as', 1, 'pique-7').
     *
     * @return list<TrickCard>
     */
    private static function trick(int|string ...$items): array
    {
        $trick = [];

        foreach (array_chunk($items, 2) as [$position, $code]) {
            $trick[] = new TrickCard((int) $position, Card::fromCode((string) $code));
        }

        return $trick;
    }

    /**
     * @param list<Card> $cards
     *
     * @return list<string>
     */
    private static function codes(array $cards): array
    {
        $codes = array_map(static fn (Card $card): string => $card->code(), $cards);
        sort($codes);

        return $codes;
    }
}
