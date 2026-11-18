<?php

declare(strict_types=1);

namespace App\Game\Tarot;

/**
 * Robot de tarot : enchères d'après la force de la main, écart pour se faire des coupes,
 * jeu de la carte simple (prendre au plus juste, charger son partenaire, sauver le Petit, placer l'Excuse).
 * Il joue toujours un coup permis : le moteur vérifie chaque carte.
 */
final readonly class TarotBot
{
    public function __construct(
        private TarotEngine $engine,
    ) {
    }

    public function act(TarotState $state): TarotActionResult
    {
        $position = $state->turn;

        return match ($state->phase) {
            TarotPhase::Bidding => $this->engine->bid($state, $position, $this->chooseContract($state, $position)),
            TarotPhase::Call => $this->engine->call($state, $position, $this->chooseCall($state, $position)),
            TarotPhase::Dog => $this->engine->discard($state, $position, self::chooseDiscard($state->hands[$position], TarotRules::dogSize($state->players))),
            default => $this->playTurn($state, $position),
        };
    }

    private function playTurn(TarotState $state, int $position): TarotActionResult
    {
        $result = new TarotActionResult();
        $poignees = $this->engine->poigneesFor($state, $position);

        // Un robot annonce toujours sa plus grosse poignée
        if ([] !== $poignees) {
            $result = $this->engine->poignee($state, $position, $poignees[\count($poignees) - 1]);
        }

        return $result->merge($this->engine->play($state, $position, self::chooseCard($state, $position)));
    }

    // ---------- Enchères ----------

    private function chooseContract(TarotState $state, int $position): ?TarotContract
    {
        // Une main de 24 cartes (3 joueurs) vaut plus qu'une main de 15 (5 joueurs) : on ramène à 18 cartes
        $hand = $state->hands[$position];
        $value = intdiv(self::handValue($hand) * 18, max(1, \count($hand)));
        $wanted = match (true) {
            $value >= 62 => TarotContract::GardeContre,
            $value >= 54 => TarotContract::GardeSans,
            $value >= 45 => TarotContract::Garde,
            $value >= 35 => TarotContract::Petite,
            default => null,
        };

        if (null === $wanted || (null !== $state->contract && $wanted->level() <= $state->contract->level())) {
            return null;
        }

        return $wanted;
    }

    /**
     * Force d'une main (méthode de comptage classique : bouts, atouts, honneurs, longues, coupes).
     *
     * @param list<TarotCard> $hand
     */
    public static function handValue(array $hand): int
    {
        $trumps = TarotRules::ofSuit($hand, TarotSuit::Trumps);
        $count = \count($trumps);
        $value = 2 * $count;

        foreach ($hand as $card) {
            $value += match (true) {
                $card->isExcuse() => 8,
                $card->isTrump() && 21 === $card->rank => 10,
                $card->isPetit() => $count >= 6 ? 8 : ($count >= 5 ? 5 : 0),
                $card->isTrump() && $card->rank >= 16 => 1,
                $card->isKing() => 6,
                TarotCard::QUEEN === $card->rank && $card->suit->isColour() => 3,
                TarotCard::KNIGHT === $card->rank && $card->suit->isColour() => 2,
                TarotCard::JACK === $card->rank && $card->suit->isColour() => 1,
                default => 0,
            };
        }

        foreach (TarotSuit::colours() as $suit) {
            $length = \count(TarotRules::ofSuit($hand, $suit));
            $value += $length >= 5 ? 2 * ($length - 4) : 0;
            $value += 0 === $length && $count >= 5 ? 3 : (1 === $length && $count >= 5 ? 1 : 0);
        }

        return $value;
    }

    /**
     * À 5 joueurs : appeler le Roi de la couleur où l'on est le plus long sans l'avoir (un partenaire utile).
     */
    private function chooseCall(TarotState $state, int $position): TarotCard
    {
        $hand = $state->hands[$position];
        $options = $this->engine->callable($state);
        $missing = array_values(array_filter($options, static fn (TarotCard $card): bool => [] === array_filter($hand, static fn (TarotCard $held): bool => $held->equals($card))));
        $choices = [] === $missing ? $options : $missing;
        usort($choices, static fn (TarotCard $a, TarotCard $b): int => \count(TarotRules::ofSuit($hand, $b->suit)) <=> \count(TarotRules::ofSuit($hand, $a->suit)));

        return $choices[0];
    }

    // ---------- Écart ----------

    /**
     * Les 6 cartes à écarter : d'abord les couleurs les plus courtes (pour couper ensuite),
     * en y mettant les points (Dames, Cavaliers), jamais de Roi ni de bout ; des atouts seulement s'il le faut.
     *
     * @param list<TarotCard> $hand
     *
     * @return list<TarotCard>
     */
    public static function chooseDiscard(array $hand, int $size = 6): array
    {
        [$plain, $trumps] = TarotRules::discardable($hand);
        $length = static fn (TarotSuit $suit): int => \count(TarotRules::ofSuit($hand, $suit))
            + (array_filter($hand, static fn (TarotCard $card): bool => $card->suit === $suit && $card->isKing()) ? 10 : 0);

        usort($plain, static fn (TarotCard $a, TarotCard $b): int => [$length($a->suit), $b->halfPoints()] <=> [$length($b->suit), $a->halfPoints()]);
        usort($trumps, static fn (TarotCard $a, TarotCard $b): int => $a->rank <=> $b->rank);

        return \array_slice([...$plain, ...$trumps], 0, $size);
    }

    // ---------- Jeu de la carte ----------

    public static function chooseCard(TarotState $state, int $position): TarotCard
    {
        $legal = $state->playableCards($position);
        $hand = $state->hands[$position];

        if (1 === \count($legal)) {
            return $legal[0];
        }

        $excuse = array_values(array_filter($legal, static fn (TarotCard $card): bool => $card->isExcuse()));

        // L'Excuse se place avant la fin (au dernier pli, elle serait perdue)
        if ([] !== $excuse && \count($hand) <= 2) {
            return $excuse[0];
        }

        $others = array_values(array_filter($legal, static fn (TarotCard $card): bool => !$card->isExcuse()));

        return [] === $state->trick ? self::lead($others) : self::follow($state, $position, $others, $excuse[0] ?? null);
    }

    /**
     * @param non-empty-list<TarotCard> $legal
     */
    private static function lead(array $legal): TarotCard
    {
        $kings = array_values(array_filter($legal, static fn (TarotCard $card): bool => $card->isKing()));

        if ([] !== $kings) {
            return $kings[0];
        }

        $colours = array_values(array_filter($legal, static fn (TarotCard $card): bool => $card->suit->isColour()));

        if ([] !== $colours) {
            // La plus petite carte de la plus longue couleur
            usort($colours, static fn (TarotCard $a, TarotCard $b): int => [\count(TarotRules::ofSuit($legal, $b->suit)), $a->rank] <=> [\count(TarotRules::ofSuit($legal, $a->suit)), $b->rank]);

            return $colours[0];
        }

        // Que des atouts : le plus fort, en gardant le Petit pour la fin
        $trumps = self::withoutPetit($legal) ?: $legal;
        usort($trumps, static fn (TarotCard $a, TarotCard $b): int => $b->rank <=> $a->rank);

        return $trumps[0];
    }

    /**
     * @param list<TarotCard> $legal cartes permises, sans l'Excuse
     */
    private static function follow(TarotState $state, int $position, array $legal, ?TarotCard $excuse): TarotCard
    {
        if ([] === $legal) {
            return $excuse ?? throw new TarotException('Aucune carte jouable.');
        }

        $last = $state->players - 1 === \count($state->trick);
        $hasLead = null !== TarotRules::ledSuit($state->trick);
        $winner = $hasLead ? TarotRules::winner($state->trick) : null;
        $winningCard = null;

        foreach ($state->trick as $played) {
            if ($played->position === $winner) {
                $winningCard = $played->card;
            }
        }

        $byPoints = $legal;
        usort($byPoints, static fn (TarotCard $a, TarotCard $b): int => [$a->halfPoints(), $a->isTrump() ? $a->rank : 0] <=> [$b->halfPoints(), $b->isTrump() ? $b->rank : 0]);

        // Mon camp tient le pli : je charge s'il est sûr, sinon je donne peu
        // Ce que je sais de mon camp (à 5 joueurs, le partenaire reste caché tant que le Roi appelé n'est pas sorti)
        if (null !== $winner && $state->seenAsAlly($position, $winner)) {
            $safe = $last || ($winningCard?->isTrump() && $winningCard->rank >= 18) || ($winningCard?->isKing() && !$winningCard->isTrump() && self::noTrumpYet($state));

            if ($safe) {
                return $byPoints[\count($byPoints) - 1];
            }

            return self::withoutPetit($byPoints)[0] ?? $byPoints[0];
        }

        // L'adversaire tient le pli : le prendre au plus juste
        $winning = array_values(array_filter($legal, static fn (TarotCard $card): bool => TarotRules::winner([...$state->trick, new TrickCard($position, $card)]) === $position));

        if ([] !== $winning) {
            foreach ($winning as $card) {
                if ($card->isPetit() && $last) {
                    return $card;
                }
            }

            usort($winning, static fn (TarotCard $a, TarotCard $b): int => [$a->isTrump() ? 1 : 0, $a->rank] <=> [$b->isTrump() ? 1 : 0, $b->rank]);
            $candidates = self::withoutPetit($winning);

            return $candidates[0] ?? $winning[0];
        }

        // Pli perdu : la plus petite carte, l'Excuse pour sauver une carte qui compte
        $cheapest = self::withoutPetit($byPoints)[0] ?? $byPoints[0];

        if (null !== $excuse && $cheapest->halfPoints() >= 5) {
            return $excuse;
        }

        return $cheapest;
    }

    private static function noTrumpYet(TarotState $state): bool
    {
        return [] === array_filter($state->trick, static fn (TrickCard $played): bool => $played->card->isTrump());
    }

    /**
     * @param list<TarotCard> $cards
     *
     * @return list<TarotCard>
     */
    private static function withoutPetit(array $cards): array
    {
        return array_values(array_filter($cards, static fn (TarotCard $card): bool => !$card->isPetit()));
    }
}
