<?php

declare(strict_types=1);

namespace App\Game\Belote;

/**
 * Une annonce : une suite (3, 4, 5 cartes ou plus de la même couleur qui se suivent)
 * ou un carré (les 4 cartes d'une hauteur, sauf 8 et 7).
 */
final readonly class Announcement
{
    /**
     * @param list<Card> $cards
     */
    public function __construct(
        public int $points,
        public array $cards,
        /** Couleur d'une suite ; null pour un carré. */
        public ?Suit $suit,
    ) {
    }

    public function isSquare(): bool
    {
        return null === $this->suit;
    }

    public function name(): string
    {
        if ($this->isSquare()) {
            return match ($this->cards[0]->rank) {
                Rank::Jack => 'Carré de Valets',
                Rank::Nine => 'Carré de 9',
                Rank::Ace => 'Carré d’As',
                Rank::Ten => 'Carré de 10',
                Rank::King => 'Carré de Rois',
                default => 'Carré de Dames',
            };
        }

        return match (true) {
            \count($this->cards) >= 5 => 'Cent',
            4 === \count($this->cards) => 'Cinquante',
            default => 'Tierce',
        };
    }

    public function isTrump(Suit $trump): bool
    {
        return $this->suit === $trump;
    }

    /**
     * @return array{name: string, points: int, cards: list<string>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name(),
            'points' => $this->points,
            'cards' => array_map(static fn (Card $card): string => $card->code(), $this->cards),
        ];
    }

    /**
     * @param array{points: int, cards: list<string>, suit: ?string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['points'], array_map(Card::fromCode(...), $data['cards']), null === $data['suit'] ? null : Suit::from($data['suit']));
    }

    /**
     * @return array{points: int, cards: list<string>, suit: ?string}
     */
    public function toState(): array
    {
        return ['points' => $this->points, 'cards' => $this->toArray()['cards'], 'suit' => $this->suit?->value];
    }
}
