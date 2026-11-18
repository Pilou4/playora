<?php

declare(strict_types=1);

namespace App\Game\Spider;

/**
 * Une des 104 cartes. Les cartes existent en plusieurs exemplaires : le code les distingue
 * (« pique-roi-1 », « coeur-7-2 »…), et c'est lui qui circule entre la page et le serveur.
 */
final readonly class SpiderCard
{
    public const array SUITS = ['pique', 'coeur', 'carreau', 'trefle'];
    private const array FACES = [1 => 'as', 11 => 'valet', 12 => 'dame', 13 => 'roi'];

    public function __construct(
        public string $suit,
        public int $rank,
        public int $copy,
    ) {
    }

    /**
     * @throws SpiderException
     */
    public static function fromCode(string $code): self
    {
        if (1 !== preg_match('/^(pique|coeur|carreau|trefle)-(as|[2-9]|10|valet|dame|roi)-([1-8])$/', $code, $match)) {
            throw new SpiderException('Carte inconnue.');
        }

        $rank = array_flip(self::FACES)[$match[2]] ?? (int) $match[2];

        return new self($match[1], $rank, (int) $match[3]);
    }

    /** « pique-roi-1 ». */
    public function code(): string
    {
        return $this->suit.'-'.(self::FACES[$this->rank] ?? (string) $this->rank).'-'.$this->copy;
    }

    /** L'image de la carte (sans l'exemplaire) : « pique/roi ». */
    public function face(): string
    {
        return $this->suit.'/'.(self::FACES[$this->rank] ?? (string) $this->rank);
    }

    /**
     * Les 104 cartes du niveau, dans l'ordre.
     *
     * @return list<self>
     */
    public static function deck(SpiderLevel $level): array
    {
        $suits = $level->suits();
        $copies = intdiv(8, \count($suits));
        $cards = [];

        foreach ($suits as $suit) {
            foreach (range(1, $copies) as $copy) {
                foreach (range(1, 13) as $rank) {
                    $cards[] = new self($suit, $rank, $copy);
                }
            }
        }

        return $cards;
    }
}
