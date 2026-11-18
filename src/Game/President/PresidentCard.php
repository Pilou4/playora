<?php

declare(strict_types=1);

namespace App\Game\President;

/**
 * Une carte du Président (un jeu de 52 cartes). Le code reprend le nom de l'image : « coeur-7 », « pique-as ».
 * Ordre des valeurs, de la plus faible à la plus forte : 3, 4… 10, Valet, Dame, Roi, As, 2.
 */
final readonly class PresidentCard
{
    public const array SUITS = ['pique', 'coeur', 'carreau', 'trefle'];
    /** Les valeurs dans l'ordre de force (index = force, de 0 pour le 3 à 12 pour le 2). */
    public const array RANKS = ['3', '4', '5', '6', '7', '8', '9', '10', 'valet', 'dame', 'roi', 'as', '2'];
    /** Noms des valeurs dans les messages : « les 9 », « les Dames ». */
    private const array PLURALS = ['valet' => 'Valets', 'dame' => 'Dames', 'roi' => 'Rois', 'as' => 'As'];

    public function __construct(
        public string $suit,
        public string $rank,
    ) {
    }

    /**
     * @throws PresidentException
     */
    public static function fromCode(string $code): self
    {
        if (1 !== preg_match('/^(pique|coeur|carreau|trefle)-([2-9]|10|valet|dame|roi|as)$/', $code, $match)) {
            throw new PresidentException('Carte inconnue.');
        }

        return new self($match[1], $match[2]);
    }

    public function code(): string
    {
        return $this->suit.'-'.$this->rank;
    }

    /** Force de la carte : 0 pour le 3, 12 pour le 2. */
    public function power(): int
    {
        return (int) array_search($this->rank, self::RANKS, true);
    }

    public static function powerOf(string $code): int
    {
        return self::fromCode($code)->power();
    }

    /** « 9 », « Dames », « As » : pour les messages. */
    public static function rankPlural(string $rank): string
    {
        return self::PLURALS[$rank] ?? $rank;
    }

    /**
     * Les 52 cartes.
     *
     * @return list<self>
     */
    public static function deck(): array
    {
        $cards = [];

        foreach (self::SUITS as $suit) {
            foreach (self::RANKS as $rank) {
                $cards[] = new self($suit, $rank);
            }
        }

        return $cards;
    }

    /**
     * Cartes triées de la plus faible à la plus forte (à force égale : pique, cœur, carreau, trèfle).
     *
     * @param list<string> $codes
     *
     * @return list<string>
     */
    public static function sort(array $codes): array
    {
        usort($codes, static function (string $a, string $b): int {
            $ca = self::fromCode($a);
            $cb = self::fromCode($b);

            return [$ca->power(), array_search($ca->suit, self::SUITS, true)] <=> [$cb->power(), array_search($cb->suit, self::SUITS, true)];
        });

        return $codes;
    }
}
