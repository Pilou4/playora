<?php

declare(strict_types=1);

namespace App\Game\Belote;

enum Suit: string
{
    case Spades = 'pique';
    case Hearts = 'coeur';
    case Diamonds = 'carreau';
    case Clubs = 'trefle';

    public function label(): string
    {
        return match ($this) {
            self::Spades => 'Pique',
            self::Hearts => 'Cœur',
            self::Diamonds => 'Carreau',
            self::Clubs => 'Trèfle',
        };
    }
}
