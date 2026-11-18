<?php

declare(strict_types=1);

namespace App\Game\Tarot;

/**
 * Les 4 couleurs, les atouts et l'Excuse.
 */
enum TarotSuit: string
{
    case Spades = 'pique';
    case Hearts = 'coeur';
    case Diamonds = 'carreau';
    case Clubs = 'trefle';
    case Trumps = 'atout';
    case Excuse = 'excuse';

    public function isColour(): bool
    {
        return self::Trumps !== $this && self::Excuse !== $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Spades => 'Pique',
            self::Hearts => 'Cœur',
            self::Diamonds => 'Carreau',
            self::Clubs => 'Trèfle',
            self::Trumps => 'Atout',
            self::Excuse => 'Excuse',
        };
    }

    /**
     * @return list<self>
     */
    public static function colours(): array
    {
        return [self::Spades, self::Hearts, self::Diamonds, self::Clubs];
    }
}
