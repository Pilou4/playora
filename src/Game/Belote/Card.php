<?php

declare(strict_types=1);

namespace App\Game\Belote;

/**
 * Une carte. Son code (« coeur-valet ») sert d'identifiant partout : état enregistré, API, images.
 */
final readonly class Card
{
    public function __construct(
        public Suit $suit,
        public Rank $rank,
    ) {
    }

    /**
     * @throws BeloteException
     */
    public static function fromCode(string $code): self
    {
        [$suit, $rank] = array_pad(explode('-', $code, 2), 2, '');
        $suit = Suit::tryFrom($suit);
        $rank = Rank::tryFrom($rank);

        if (null === $suit || null === $rank) {
            throw new BeloteException('Carte inconnue.');
        }

        return new self($suit, $rank);
    }

    public function code(): string
    {
        return $this->suit->value.'-'.$this->rank->value;
    }

    public function isTrump(Suit $trump): bool
    {
        return $this->suit === $trump;
    }

    public function points(Suit $trump): int
    {
        return $this->isTrump($trump) ? $this->rank->trumpPoints() : $this->rank->plainPoints();
    }

    public function strength(Suit $trump): int
    {
        return $this->isTrump($trump) ? $this->rank->trumpStrength() : $this->rank->plainStrength();
    }

    public function equals(self $other): bool
    {
        return $this->suit === $other->suit && $this->rank === $other->rank;
    }
}
