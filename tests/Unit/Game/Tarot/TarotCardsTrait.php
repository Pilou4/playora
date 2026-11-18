<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Tarot;

use App\Game\Tarot\TarotCard;
use App\Game\Tarot\TrickCard;

/**
 * Écriture courte des cartes dans les tests : c('coeur-roi'), cs('atout-21', 'excuse').
 */
trait TarotCardsTrait
{
    private static function c(string $code): TarotCard
    {
        return TarotCard::fromCode($code);
    }

    /**
     * @return list<TarotCard>
     */
    private static function cs(string ...$codes): array
    {
        return array_map(TarotCard::fromCode(...), $codes);
    }

    /**
     * @return list<TrickCard>
     */
    private static function trick(int|string ...$items): array
    {
        $trick = [];

        foreach (array_chunk($items, 2) as [$position, $code]) {
            $trick[] = new TrickCard((int) $position, TarotCard::fromCode((string) $code));
        }

        return $trick;
    }

    /**
     * @param list<TarotCard> $cards
     *
     * @return list<string>
     */
    private static function codes(array $cards): array
    {
        $codes = array_map(static fn (TarotCard $card): string => $card->code(), $cards);
        sort($codes);

        return $codes;
    }
}
