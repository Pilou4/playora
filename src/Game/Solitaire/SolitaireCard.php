<?php

declare(strict_types=1);

namespace App\Game\Solitaire;

/**
 * Une des 52 cartes : couleur (pique, cœur, carreau, trèfle) et hauteur de 1 (As) à 13 (Roi).
 */
final readonly class SolitaireCard
{
    public const array SUITS = ['pique', 'coeur', 'carreau', 'trefle'];
    private const array FACES = [1 => 'as', 11 => 'valet', 12 => 'dame', 13 => 'roi'];

    public function __construct(
        public string $suit,
        public int $rank,
    ) {
    }

    /**
     * @throws SolitaireException
     */
    public static function fromCode(string $code): self
    {
        [$suit, $rank] = array_pad(explode('-', $code, 2), 2, '');
        $faces = array_flip(self::FACES);
        $value = $faces[$rank] ?? (ctype_digit($rank) ? (int) $rank : 0);

        if (!\in_array($suit, self::SUITS, true) || $value < 1 || $value > 13 || (ctype_digit($rank) && isset(self::FACES[$value]))) {
            throw new SolitaireException('Carte inconnue.');
        }

        return new self($suit, $value);
    }

    /** « coeur-as », « pique-7 », « trefle-roi ». */
    public function code(): string
    {
        return $this->suit.'-'.(self::FACES[$this->rank] ?? (string) $this->rank);
    }

    public function isRed(): bool
    {
        return 'coeur' === $this->suit || 'carreau' === $this->suit;
    }

    public function equals(self $other): bool
    {
        return $this->suit === $other->suit && $this->rank === $other->rank;
    }

    /**
     * Les 52 cartes, dans l'ordre.
     *
     * @return list<self>
     */
    public static function deck(): array
    {
        $cards = [];

        foreach (self::SUITS as $suit) {
            foreach (range(1, 13) as $rank) {
                $cards[] = new self($suit, $rank);
            }
        }

        return $cards;
    }
}
