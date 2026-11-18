<?php

declare(strict_types=1);

namespace App\Game\Rami;

/**
 * Une des 108 cartes (2 jeux de 52 et 4 jokers). Chaque carte existe en deux exemplaires : le code les distingue
 * (« coeur-7-1 », « coeur-7-2 ») ; les jokers sont « joker-1 » à « joker-4 » (1 et 2 rouges, 3 et 4 noirs).
 */
final readonly class RamiCard
{
    public const array SUITS = ['pique', 'coeur', 'carreau', 'trefle'];
    public const int JOKERS = 4;
    private const array FACES = [1 => 'as', 11 => 'valet', 12 => 'dame', 13 => 'roi'];

    public function __construct(
        /** null pour un joker */
        public ?string $suit,
        /** 1 (As) à 13 (Roi) ; 0 pour un joker */
        public int $rank,
        public int $copy,
    ) {
    }

    /**
     * @throws RamiException
     */
    public static function fromCode(string $code): self
    {
        if (1 === preg_match('/^joker-([1-4])$/', $code, $match)) {
            return new self(null, 0, (int) $match[1]);
        }

        if (1 !== preg_match('/^(pique|coeur|carreau|trefle)-(as|[2-9]|10|valet|dame|roi)-([12])$/', $code, $match)) {
            throw new RamiException('Carte inconnue.');
        }

        return new self($match[1], array_flip(self::FACES)[$match[2]] ?? (int) $match[2], (int) $match[3]);
    }

    public function code(): string
    {
        return $this->isJoker() ? 'joker-'.$this->copy : $this->suit.'-'.self::rankName($this->rank).'-'.$this->copy;
    }

    public function isJoker(): bool
    {
        return null === $this->suit;
    }

    /** Pénalité de la carte restée en main à la fin d'une manche : joker 20, As 11, figures 10, sinon sa valeur. */
    public function penalty(): int
    {
        return match (true) {
            $this->isJoker() => 20,
            1 === $this->rank => 11,
            default => min(10, $this->rank),
        };
    }

    /** « as », « 7 », « roi ». */
    public static function rankName(int $rank): string
    {
        return self::FACES[$rank] ?? (string) $rank;
    }

    /**
     * Les 108 cartes, dans l'ordre.
     *
     * @return list<self>
     */
    public static function deck(): array
    {
        $cards = [];

        foreach ([1, 2] as $copy) {
            foreach (self::SUITS as $suit) {
                foreach (range(1, 13) as $rank) {
                    $cards[] = new self($suit, $rank, $copy);
                }
            }
        }

        foreach (range(1, self::JOKERS) as $copy) {
            $cards[] = new self(null, 0, $copy);
        }

        return $cards;
    }
}
