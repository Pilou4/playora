<?php

declare(strict_types=1);

namespace App\Game\Belote;

/**
 * Ce qu'un joueur sait pendant une donne, comme à une vraie table : sa main et les cartes déjà jouées.
 * Il en déduit les cartes encore cachées et qui n'a plus de quoi fournir une couleur.
 * (Le robot ne regarde jamais le jeu des autres.)
 */
final readonly class BeloteMemory
{
    /**
     * @param list<Card>                 $unseen cartes que ce joueur n'a pas vues (dans les autres mains)
     * @param array<int, array<string, true>> $voids  place => couleurs qu'elle n'a plus
     */
    private function __construct(
        public Suit $trump,
        private array $unseen,
        private array $voids,
    ) {
    }

    public static function of(BeloteState $state, int $position): self
    {
        $trump = $state->trump ?? throw new BeloteException('Aucun atout.');
        $seen = [];

        foreach ([...$state->hand($position), ...array_map(static fn (TrickCard $played): Card => $played->card, $state->history)] as $card) {
            $seen[$card->code()] = true;
        }

        $unseen = array_values(array_filter(Deck::cards(), static fn (Card $card): bool => !isset($seen[$card->code()])));

        return new self($trump, $unseen, self::voids($state->history, $trump));
    }

    public function isVoid(int $position, Suit $suit): bool
    {
        return isset($this->voids[$position][$suit->value]);
    }

    /**
     * @return list<Card>
     */
    public function unseen(Suit $suit): array
    {
        return array_values(array_filter($this->unseen, static fn (Card $card): bool => $card->suit === $suit));
    }

    /** Plus aucune carte cachée de cette couleur ne bat celle-ci. */
    public function isMaster(Card $card): bool
    {
        foreach ($this->unseen($card->suit) as $other) {
            if ($other->strength($this->trump) > $card->strength($this->trump)) {
                return false;
            }
        }

        return true;
    }

    /**
     * La carte qui gagne le pli peut-elle encore être battue par l'un de ces joueurs ?
     *
     * @param list<int> $players joueurs qui jouent après, dans le camp adverse
     */
    public function canBeBeaten(Card $winning, Suit $led, array $players): bool
    {
        $trump = $this->trump;
        $higher = static fn (array $cards): bool => [] !== array_filter($cards, static fn (Card $card): bool => $card->strength($trump) > $winning->strength($trump));

        foreach ($players as $player) {
            if (!$this->isVoid($player, $led)) {
                // Il peut fournir : il ne bat la carte que si elle est de la couleur demandée
                if ($winning->suit === $led && $higher($this->unseen($led))) {
                    return true;
                }

                continue;
            }

            if ($led === $trump || $this->isVoid($player, $trump)) {
                continue;
            }

            // Il n'a plus la couleur mais peut avoir de l'atout : il coupe (ou surcoupe)
            if ($winning->isTrump($trump) ? $higher($this->unseen($trump)) : [] !== $this->unseen($trump)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Qui n'a plus quelle couleur, d'après les plis déjà joués : ne pas fournir prouve qu'on n'en a plus ;
     * ne pas couper alors que l'adversaire est maître prouve qu'on n'a plus d'atout.
     *
     * @param list<TrickCard> $history
     *
     * @return array<int, array<string, true>>
     */
    private static function voids(array $history, Suit $trump): array
    {
        $voids = [];

        foreach (array_chunk($history, 4) as $trick) {
            $led = $trick[0]->card->suit;

            foreach ($trick as $index => $played) {
                if (0 === $index || $played->card->suit === $led) {
                    continue;
                }

                $voids[$played->position][$led->value] = true;
                $before = \array_slice($trick, 0, $index);
                $partnerWins = BeloteRules::team(BeloteRules::winner($before, $trump)) === BeloteRules::team($played->position);

                if (!$played->card->isTrump($trump) && !$partnerWins) {
                    $voids[$played->position][$trump->value] = true;
                }
            }
        }

        return $voids;
    }
}
