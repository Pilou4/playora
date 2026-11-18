<?php

declare(strict_types=1);

namespace App\Game\Rami;

/**
 * Une combinaison posée : une suite (au moins 3 cartes qui se suivent dans une même couleur) ou une série
 * (brelan ou carré : 3 ou 4 cartes de même hauteur, de couleurs toutes différentes).
 *
 * Chaque joker prend la place d'une carte précise (« face ») : c'est elle qu'il vaut, et c'est elle
 * qu'il faut poser pour le reprendre. Une combinaison compte toujours au moins deux vraies cartes.
 * L'As se place avant le 2 ou après le Roi, jamais des deux côtés à la fois.
 */
final readonly class RamiMeld
{
    public const string RUN = 'suite';
    public const string SET = 'serie';

    /**
     * @param list<array{code: string, face: string}> $cards dans l'ordre (suite : de la plus basse à la plus haute)
     */
    private function __construct(
        public string $kind,
        public array $cards,
        public int $points,
    ) {
    }

    /**
     * La combinaison formée par ces cartes, dans le bon ordre et avec la place de chaque joker.
     *
     * @param list<string> $codes
     *
     * @throws RamiException
     */
    public static function build(array $codes): self
    {
        if (\count($codes) < 3) {
            throw new RamiException('Une combinaison compte au moins 3 cartes.');
        }

        if (\count($codes) !== \count(array_unique($codes))) {
            throw new RamiException('Carte en double.');
        }

        $cards = array_map(static fn (string $code): RamiCard => RamiCard::fromCode($code), $codes);
        $naturals = array_values(array_filter($cards, static fn (RamiCard $card): bool => !$card->isJoker()));
        $jokers = array_values(array_filter($cards, static fn (RamiCard $card): bool => $card->isJoker()));

        if (\count($naturals) < 2) {
            throw new RamiException('Une combinaison compte au moins deux vraies cartes.');
        }

        return self::set($naturals, $jokers)
            ?? self::run($naturals, $jokers)
            ?? throw new RamiException('Ces cartes ne forment ni une suite (même couleur, cartes qui se suivent) ni un brelan ou un carré (même hauteur, couleurs différentes).');
    }

    /**
     * @param array{kind: string, cards: list<array{code: string, face: string}>, points: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['kind'], $data['cards'], $data['points']);
    }

    /**
     * @return array{kind: string, cards: list<array{code: string, face: string}>, points: int}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'cards' => $this->cards, 'points' => $this->points];
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_column($this->cards, 'code');
    }

    /** La même combinaison, avec une carte de plus. */
    public function with(string $code): self
    {
        return self::build([...$this->codes(), $code]);
    }

    /**
     * Le joker qui tient la place de cette carte (suite : la même carte ; série : une couleur qui manque), ou null.
     */
    public function jokerFor(RamiCard $card): ?string
    {
        foreach ($this->cards as $entry) {
            if (!str_starts_with($entry['code'], 'joker-')) {
                continue;
            }

            [$suit, $rank] = explode('-', $entry['face']);

            if (self::RUN === $this->kind ? $entry['face'] === $card->suit.'-'.RamiCard::rankName($card->rank) : $rank === RamiCard::rankName($card->rank) && !$this->hasSuit($card->suit)) {
                return $entry['code'];
            }
        }

        return null;
    }

    private function hasSuit(?string $suit): bool
    {
        foreach ($this->cards as $entry) {
            if (!str_starts_with($entry['code'], 'joker-') && str_starts_with($entry['face'], $suit.'-')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Brelan ou carré : même hauteur, couleurs différentes, 4 cartes au plus.
     *
     * @param list<RamiCard> $naturals
     * @param list<RamiCard> $jokers
     */
    private static function set(array $naturals, array $jokers): ?self
    {
        $rank = $naturals[0]->rank;
        $suits = array_map(static fn (RamiCard $card): string => (string) $card->suit, $naturals);

        if (\count($naturals) + \count($jokers) > 4 || \count($suits) !== \count(array_unique($suits))) {
            return null;
        }

        foreach ($naturals as $card) {
            if ($card->rank !== $rank) {
                return null;
            }
        }

        $name = RamiCard::rankName($rank);
        $cards = array_map(static fn (RamiCard $card): array => ['code' => $card->code(), 'face' => $card->suit.'-'.$name], $naturals);
        $missing = array_values(array_diff(RamiCard::SUITS, $suits));

        foreach ($jokers as $index => $joker) {
            $cards[] = ['code' => $joker->code(), 'face' => $missing[$index].'-'.$name];
        }

        return new self(self::SET, $cards, \count($cards) * self::value($rank));
    }

    /**
     * Suite : même couleur, cartes qui se suivent, les jokers bouchent les trous puis prolongent vers le haut
     * (ou vers le bas une fois le haut atteint). L'As vaut 1 avant le 2, ou 14 après le Roi.
     *
     * @param list<RamiCard> $naturals
     * @param list<RamiCard> $jokers
     */
    private static function run(array $naturals, array $jokers): ?self
    {
        $suit = $naturals[0]->suit;

        foreach ($naturals as $card) {
            if ($card->suit !== $suit) {
                return null;
            }
        }

        foreach ([false, true] as $aceHigh) {
            $ranks = array_map(static fn (RamiCard $card): int => $aceHigh && 1 === $card->rank ? 14 : $card->rank, $naturals);
            sort($ranks);

            if (\count($ranks) !== \count(array_unique($ranks))) {
                return null;
            }

            $low = $ranks[0];
            $high = $ranks[\count($ranks) - 1];
            $spare = \count($jokers) - (($high - $low + 1) - \count($ranks));

            if ($spare < 0) {
                continue;
            }

            // Les jokers en trop prolongent la suite, sans jamais faire le tour (As-Roi d'un côté, As-2 de l'autre)
            $top = $aceHigh ? 14 : 13;
            $bottom = $aceHigh ? 2 : 1;
            $up = min($spare, $top - $high);
            $high += $up;
            $low -= $spare - $up;

            if ($low < $bottom) {
                continue;
            }

            $byRank = [];

            foreach ($naturals as $card) {
                $byRank[$aceHigh && 1 === $card->rank ? 14 : $card->rank] = $card->code();
            }

            $cards = [];
            $points = 0;
            $joker = 0;

            foreach (range($low, $high) as $rank) {
                $face = $suit.'-'.RamiCard::rankName(14 === $rank ? 1 : $rank);
                $cards[] = ['code' => $byRank[$rank] ?? $jokers[$joker++]->code(), 'face' => $face];
                $points += 1 === $rank ? 1 : self::value($rank);
            }

            return new self(self::RUN, $cards, $points);
        }

        return null;
    }

    /** Valeur d'une carte posée : As 11 (1 en bas d'une suite), figures 10, sinon sa valeur. */
    private static function value(int $rank): int
    {
        return match (true) {
            1 === $rank, 14 === $rank => 11,
            default => min(10, $rank),
        };
    }
}
