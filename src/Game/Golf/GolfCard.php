<?php

declare(strict_types=1);

namespace App\Game\Golf;

/**
 * Une carte du Golf. Un jeu de 52 cartes, deux à partir de 5 joueurs : le code distingue les exemplaires
 * (« coeur-7-1 », « coeur-7-2 »).
 */
final readonly class GolfCard
{
    public const array SUITS = ['pique', 'coeur', 'carreau', 'trefle'];
    private const array FACES = [1 => 'as', 11 => 'valet', 12 => 'dame', 13 => 'roi'];

    public function __construct(
        public string $suit,
        /** 1 (As) à 13 (Roi) */
        public int $rank,
        public int $copy,
    ) {
    }

    /**
     * @throws GolfException
     */
    public static function fromCode(string $code): self
    {
        if (1 !== preg_match('/^(pique|coeur|carreau|trefle)-(as|[2-9]|10|valet|dame|roi)-([12])$/', $code, $match)) {
            throw new GolfException('Carte inconnue.');
        }

        return new self($match[1], array_flip(self::FACES)[$match[2]] ?? (int) $match[2], (int) $match[3]);
    }

    public function code(): string
    {
        return $this->suit.'-'.(self::FACES[$this->rank] ?? (string) $this->rank).'-'.$this->copy;
    }

    /** Points de la carte : As 1, 2 = −2, 3 à 10 leur valeur, Valet et Dame 10, Roi 0. */
    public function points(): int
    {
        return match (true) {
            2 === $this->rank => -2,
            13 === $this->rank => 0,
            default => min(10, $this->rank),
        };
    }

    /**
     * Les cartes : un jeu de 52, ou deux.
     *
     * @return list<self>
     */
    public static function deck(int $decks): array
    {
        $cards = [];

        foreach (range(1, $decks) as $copy) {
            foreach (self::SUITS as $suit) {
                foreach (range(1, 13) as $rank) {
                    $cards[] = new self($suit, $rank, $copy);
                }
            }
        }

        return $cards;
    }
}
