<?php

declare(strict_types=1);

namespace App\Game\Tarot;

/**
 * Les enchères, de la plus petite à la plus forte, et leur coefficient.
 */
enum TarotContract: string
{
    case Petite = 'petite';
    case Garde = 'garde';
    case GardeSans = 'garde-sans';
    case GardeContre = 'garde-contre';

    public function label(): string
    {
        return match ($this) {
            self::Petite => 'Petite',
            self::Garde => 'Garde',
            self::GardeSans => 'Garde sans',
            self::GardeContre => 'Garde contre',
        };
    }

    public function multiplier(): int
    {
        return match ($this) {
            self::Petite => 1,
            self::Garde => 2,
            self::GardeSans => 4,
            self::GardeContre => 6,
        };
    }

    public function level(): int
    {
        return match ($this) {
            self::Petite => 1,
            self::Garde => 2,
            self::GardeSans => 3,
            self::GardeContre => 4,
        };
    }

    /** Petite et garde : le preneur prend le chien et fait son écart. */
    public function usesDog(): bool
    {
        return self::Petite === $this || self::Garde === $this;
    }
}
