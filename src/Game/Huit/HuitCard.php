<?php

declare(strict_types=1);

namespace App\Game\Huit;

/**
 * Une carte du 8 américain. Un jeu de 52 cartes, deux à partir de 5 joueurs : le code distingue les exemplaires
 * (« coeur-7-1 », « coeur-7-2 »).
 */
final readonly class HuitCard
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
     * @throws HuitException
     */
    public static function fromCode(string $code): self
    {
        if (1 !== preg_match('/^(pique|coeur|carreau|trefle)-(as|[2-9]|10|valet|dame|roi)-([12])$/', $code, $match)) {
            throw new HuitException('Carte inconnue.');
        }

        return new self($match[1], array_flip(self::FACES)[$match[2]] ?? (int) $match[2], (int) $match[3]);
    }

    public function code(): string
    {
        return $this->suit.'-'.(self::FACES[$this->rank] ?? (string) $this->rank).'-'.$this->copy;
    }

    /** Points comptés à la fin d'une manche pour une carte restée en main : 8 = 50, As 11, figures 10, sinon sa valeur. */
    public function penalty(): int
    {
        return match (true) {
            8 === $this->rank => 50,
            1 === $this->rank => 11,
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
