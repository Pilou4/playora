<?php

declare(strict_types=1);

namespace App\Game\Tarot;

/**
 * Une carte du tarot. Couleurs : 1 à 10, Valet (11), Cavalier (12), Dame (13), Roi (14).
 * Atouts : 1 (le Petit) à 21. Excuse : rang 0.
 *
 * Les points sont comptés en demi-points (le tarot compte des 0,5) : 182 demi-points, soit 91 points.
 */
final readonly class TarotCard
{
    public const int JACK = 11;
    public const int KNIGHT = 12;
    public const int QUEEN = 13;
    public const int KING = 14;

    private const array FACES = [self::JACK => 'valet', self::KNIGHT => 'cavalier', self::QUEEN => 'dame', self::KING => 'roi'];

    public function __construct(
        public TarotSuit $suit,
        public int $rank,
    ) {
    }

    /**
     * @throws TarotException
     */
    public static function fromCode(string $code): self
    {
        if ('excuse' === $code) {
            return new self(TarotSuit::Excuse, 0);
        }

        [$suit, $rank] = array_pad(explode('-', $code, 2), 2, '');
        $suit = TarotSuit::tryFrom($suit) ?? throw new TarotException('Carte inconnue.');
        $faces = array_flip(self::FACES);
        $value = $faces[$rank] ?? (ctype_digit($rank) ? (int) $rank : 0);
        $max = TarotSuit::Trumps === $suit ? 21 : 14;

        if ($value < 1 || $value > $max || (TarotSuit::Trumps === $suit && isset($faces[$rank])) || TarotSuit::Excuse === $suit) {
            throw new TarotException('Carte inconnue.');
        }

        return new self($suit, $value);
    }

    /** « coeur-roi », « pique-7 », « atout-21 », « excuse ». */
    public function code(): string
    {
        if (TarotSuit::Excuse === $this->suit) {
            return 'excuse';
        }

        $rank = TarotSuit::Trumps === $this->suit ? (string) $this->rank : (self::FACES[$this->rank] ?? (string) $this->rank);

        return $this->suit->value.'-'.$rank;
    }

    public function isTrump(): bool
    {
        return TarotSuit::Trumps === $this->suit;
    }

    public function isExcuse(): bool
    {
        return TarotSuit::Excuse === $this->suit;
    }

    /** Les trois bouts (oudlers) : le Petit, le 21 et l'Excuse. */
    public function isBout(): bool
    {
        return $this->isExcuse() || ($this->isTrump() && (1 === $this->rank || 21 === $this->rank));
    }

    public function isPetit(): bool
    {
        return $this->isTrump() && 1 === $this->rank;
    }

    public function isKing(): bool
    {
        return $this->suit->isColour() && self::KING === $this->rank;
    }

    /** Valeur en demi-points : bouts et Rois 4,5 ; Dames 3,5 ; Cavaliers 2,5 ; Valets 1,5 ; les autres 0,5. */
    public function halfPoints(): int
    {
        if ($this->isBout()) {
            return 9;
        }

        if ($this->isTrump()) {
            return 1;
        }

        return match ($this->rank) {
            self::KING => 9,
            self::QUEEN => 7,
            self::KNIGHT => 5,
            self::JACK => 3,
            default => 1,
        };
    }

    public function equals(self $other): bool
    {
        return $this->suit === $other->suit && $this->rank === $other->rank;
    }
}
