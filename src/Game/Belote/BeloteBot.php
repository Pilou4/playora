<?php

declare(strict_types=1);

namespace App\Game\Belote;

/**
 * Robot qui joue comme un bon joueur de club, sans tricher : il ne connaît que sa main
 * et les cartes déjà jouées (voir BeloteMemory). Il remplace aussi un joueur qui quitte la partie.
 *
 * Enchères : il compte la force de sa main (atouts maîtres, as, coupes) et ne prend qu'avec de quoi faire le contrat.
 * Jeu : le preneur fait tomber les atouts ; on joue ses cartes maîtres quand personne ne peut couper ;
 * on charge le partenaire quand son pli est assuré ; on ne gaspille rien sur un pli perdu.
 */
final readonly class BeloteBot
{
    /** Force de main nécessaire pour prendre (réglée en faisant jouer des milliers de donnes). */
    public const float TAKE_THRESHOLD = 6.5;

    public function __construct(
        private BeloteEngine $engine,
        private float $takeThreshold = self::TAKE_THRESHOLD,
    ) {
    }

    public function act(BeloteState $state): ActionResult
    {
        $position = $state->turn;

        if (Phase::Auction === $state->phase) {
            return $this->auction($state, $position);
        }

        if ($state->phase->isBidding()) {
            $suit = $this->chooseTrump($state, $position);

            return null === $suit ? $this->engine->pass($state, $position) : $this->engine->take($state, $position, $suit);
        }

        return $this->engine->play($state, $position, $this->chooseCard($state, $position));
    }

    // ---------- Enchères ----------

    private function chooseTrump(BeloteState $state, int $position): ?Suit
    {
        $turned = $state->turnedCard;

        if (null === $turned) {
            return null;
        }

        // Le preneur ramasse la carte retournée, quelle que soit la couleur choisie
        $hand = [...$state->hand($position), $turned];
        $candidates = Phase::FirstBidding === $state->phase
            ? [$turned->suit]
            : array_filter(Suit::cases(), static fn (Suit $suit): bool => $suit !== $turned->suit);

        $best = null;
        $bestValue = $this->takeThreshold;

        foreach ($candidates as $suit) {
            $value = self::handValue($hand, $suit);

            if ($value >= $bestValue) {
                $best = $suit;
                $bestValue = $value;
            }
        }

        return $best;
    }

    // ---------- Enchères de coinche et contrée ----------

    /** Ce que le partenaire apporte en moyenne, ajouté à l'estimation de sa propre main (réglé par simulation). */
    public const int PARTNER_SHARE = 40;

    private function auction(BeloteState $state, int $position): ActionResult
    {
        $hand = $state->hand($position);
        $taker = $state->taker;
        $contract = $state->contract;
        $ourContract = null !== $taker && BeloteRules::team($taker) === BeloteRules::team($position);

        // Contrat adverse : coincher si nos atouts et nos As le feront chuter
        if (null !== $contract && null !== $taker && null !== $state->trump && !$ourContract && null === $state->coincher
            && self::defenseValue($hand, $state->trump) >= 162 - $contract + 5) {
            return $this->engine->coinche($state, $position);
        }

        // Notre contrat coinché : surcoincher seulement avec une très belle main
        if (null !== $state->coincher && $ourContract && 2 === $state->multiplier && null !== $state->trump
            && self::contractValue($hand, $state->trump) + self::PARTNER_SHARE >= ($contract ?? 0) + 40) {
            return $this->engine->surcoinche($state, $position);
        }

        if (null !== $state->coincher) {
            return $this->engine->pass($state, $position);
        }

        // Ma meilleure couleur et ce que l'équipe peut annoncer avec
        $best = null;
        $bestValue = 0;

        foreach (Suit::cases() as $suit) {
            $value = self::contractValue($hand, $suit);

            if ($value > $bestValue) {
                $best = $suit;
                $bestValue = $value;
            }
        }

        // Le partenaire a annoncé tout atout ou sans atout : on le laisse jouer
        if ($ourContract && TrumpMode::Suit !== $state->mode) {
            return $this->engine->pass($state, $position);
        }

        // Le partenaire a annoncé : on le soutient une fois dans sa couleur (si l'on a déjà parlé, on laisse)
        if ($ourContract && null !== $state->trump && null !== $contract) {
            if (isset($state->bids[$position])) {
                return $this->engine->pass($state, $position);
            }

            $support = self::supportValue($hand, $state->trump);
            $raise = $contract + 10 * intdiv(max(0, $support - 20), 15);

            if ($raise > $contract && $raise <= CoincheScorer::MAX_CONTRACT) {
                return $this->engine->bid($state, $position, $raise, $state->trump);
            }

            return $this->engine->pass($state, $position);
        }

        if (null === $best) {
            return $this->engine->pass($state, $position);
        }

        $total = $bestValue + self::PARTNER_SHARE;
        $max = $total >= 230 ? CoincheScorer::CAPOT_CONTRACT : min(CoincheScorer::MAX_CONTRACT, 10 * intdiv($total, 10));
        $wanted = null === $contract ? $max : min($max, $contract + 10);

        if ($wanted >= CoincheScorer::MIN_CONTRACT && (null === $contract || $wanted > $contract)) {
            return $this->engine->bid($state, $position, $wanted, $best);
        }

        return $this->engine->pass($state, $position);
    }

    /**
     * Points que ma main rapporte si cette couleur est l'atout (sans compter le partenaire).
     *
     * @param list<Card> $hand
     */
    public static function contractValue(array $hand, Suit $trump): int
    {
        $trumps = array_values(array_filter($hand, static fn (Card $card): bool => $card->isTrump($trump)));
        $ranks = array_map(static fn (Card $card): Rank => $card->rank, $trumps);
        $has = static fn (Rank $rank): bool => \in_array($rank, $ranks, true);
        $count = \count($trumps);

        if ($count < 3 && !($has(Rank::Jack) && $has(Rank::Nine))) {
            return 0;
        }

        $value = 0;

        foreach ($trumps as $card) {
            $value += match ($card->rank) {
                Rank::Jack => 22,
                Rank::Nine => 15,
                Rank::Ace => 10,
                Rank::Ten => 7,
                default => 4,
            };
        }

        $value += $has(Rank::Jack) && $has(Rank::Nine) ? 10 : 0;
        $value += max(0, $count - 3) * 10;
        $value += $has(Rank::King) && $has(Rank::Queen) ? 15 : 0;

        if (!$has(Rank::Jack)) {
            $value -= 15;
        }

        foreach (Suit::cases() as $suit) {
            if ($suit === $trump) {
                continue;
            }

            $cards = array_values(array_filter($hand, static fn (Card $card): bool => $card->suit === $suit));
            $suitRanks = array_map(static fn (Card $card): Rank => $card->rank, $cards);

            if ([] === $cards) {
                $value += $count >= 3 ? 8 : 0;
            } elseif (1 === \count($cards) && Rank::Ace !== $cards[0]->rank) {
                $value += $count >= 4 ? 4 : 0;
            }

            if (\in_array(Rank::Ace, $suitRanks, true)) {
                $value += \in_array(Rank::Ten, $suitRanks, true) ? 20 : 11;
            } elseif (\in_array(Rank::Ten, $suitRanks, true) && \count($cards) >= 2) {
                $value += 4;
            }
        }

        return max(0, $value);
    }

    /**
     * Soutien au contrat du partenaire dans sa couleur : atouts et As.
     *
     * @param list<Card> $hand
     */
    private static function supportValue(array $hand, Suit $trump): int
    {
        $value = 0;

        foreach ($hand as $card) {
            $value += match (true) {
                $card->isTrump($trump) && Rank::Jack === $card->rank => 20,
                $card->isTrump($trump) && Rank::Nine === $card->rank => 14,
                $card->isTrump($trump) => 5,
                Rank::Ace === $card->rank => 11,
                default => 0,
            };
        }

        return $value;
    }

    /**
     * Points que ma main peut prendre en défense contre cet atout (pour coincher).
     *
     * @param list<Card> $hand
     */
    private static function defenseValue(array $hand, Suit $trump): int
    {
        $trumps = array_values(array_filter($hand, static fn (Card $card): bool => $card->isTrump($trump)));
        $ranks = array_map(static fn (Card $card): Rank => $card->rank, $trumps);
        $value = 0;

        if (\in_array(Rank::Jack, $ranks, true)) {
            $value += 25;
        }

        if (\in_array(Rank::Nine, $ranks, true) && \count($trumps) >= 2) {
            $value += 20;
        }

        foreach ($hand as $card) {
            if (!$card->isTrump($trump) && Rank::Ace === $card->rank) {
                $value += 15;
            }
        }

        return $value + max(0, \count($trumps) - 2) * 6;
    }

    /**
     * @param list<Card> $hand
     */
    public static function handValue(array $hand, Suit $trump): float
    {
        $trumps = array_values(array_filter($hand, static fn (Card $card): bool => $card->isTrump($trump)));
        $ranks = array_map(static fn (Card $card): Rank => $card->rank, $trumps);
        $has = static fn (Rank $rank): bool => \in_array($rank, $ranks, true);

        if (\count($trumps) < 2 || (\count($trumps) < 3 && !($has(Rank::Jack) && $has(Rank::Nine)))) {
            return 0.0;
        }

        $value = 0.0;

        foreach ($trumps as $card) {
            $value += match ($card->rank) {
                Rank::Jack => 4.5,
                Rank::Nine => 3.0,
                Rank::Ace => 2.0,
                Rank::Ten => 1.5,
                default => 0.75,
            };
        }

        // Belote (Roi et Dame d'atout) : 20 points assurés
        if ($has(Rank::King) && $has(Rank::Queen)) {
            $value += 1.0;
        }

        // Sans le Valet ni le 9, les atouts tombent sous ceux des autres
        if (!$has(Rank::Jack) && !$has(Rank::Nine)) {
            $value *= 0.6;
        }

        foreach (Suit::cases() as $suit) {
            if ($suit === $trump) {
                continue;
            }

            $cards = array_values(array_filter($hand, static fn (Card $card): bool => $card->suit === $suit));
            $suitRanks = array_map(static fn (Card $card): Rank => $card->rank, $cards);

            if ([] === $cards) {
                // Couleur absente : on coupe
                $value += \count($trumps) >= 3 ? 0.75 : 0.25;
            } elseif (\in_array(Rank::Ace, $suitRanks, true)) {
                $value += \in_array(Rank::Ten, $suitRanks, true) ? 2.25 : 1.5;
            } elseif (\in_array(Rank::Ten, $suitRanks, true) && \count($cards) >= 2) {
                $value += 0.4;
            }
        }

        return $value;
    }

    // ---------- Jeu ----------

    private function chooseCard(BeloteState $state, int $position): Card
    {
        $legal = $state->playableCards($position);

        if (1 === \count($legal)) {
            return $legal[0];
        }

        if (TrumpMode::Suit !== $state->mode) {
            return self::modeCard($state, $position, $legal);
        }

        $memory = BeloteMemory::of($state, $position);

        return [] === $state->trick
            ? $this->lead($state, $position, $legal, $memory)
            : $this->follow($state, $position, $legal, $memory);
    }

    /**
     * Entame : faire tomber les atouts (preneur), encaisser les cartes maîtres, faire couper son partenaire.
     *
     * @param non-empty-list<Card> $legal
     */
    private function lead(BeloteState $state, int $position, array $legal, BeloteMemory $memory): Card
    {
        $trump = $memory->trump;
        $taker = $state->taker ?? $position;
        $partner = ($position + 2) % 4;
        $opponents = [($position + 1) % 4, ($position + 3) % 4];
        $trumps = self::ofSuit($legal, $trump);
        $opponentsHaveTrumps = [] !== $memory->unseen($trump)
            && [] !== array_filter($opponents, static fn (int $player): bool => !$memory->isVoid($player, $trump));
        $ourContract = BeloteRules::team($taker) === BeloteRules::team($position);

        // Notre équipe a pris : on fait tomber les atouts d'en face. Avec le Valet (ou l'atout maître), on le joue tout de suite ;
        // sinon un petit atout, pour faire sortir les gros. En défense, on n'entame jamais à l'atout.
        // On garde toujours son dernier atout pour couper, sauf s'il est maître et fait tomber le tout dernier atout caché.
        if ($ourContract && [] !== $trumps && $opponentsHaveTrumps) {
            $masters = array_values(array_filter($trumps, $memory->isMaster(...)));
            $last = 1 === \count($trumps);

            if ([] !== $masters && (!$last || \count($memory->unseen($trump)) <= 1)) {
                return self::strongest($masters, $trump);
            }

            if ([] === $masters && !$last) {
                return self::weakest($trumps, $trump);
            }
        }

        // Cartes maîtres hors atout, que personne en face ne peut couper
        $safe = array_values(array_filter($legal, static function (Card $card) use ($memory, $trump, $opponents): bool {
            return !$card->isTrump($trump) && $memory->isMaster($card) && !$memory->canBeBeaten($card, $card->suit, $opponents);
        }));

        if ([] !== $safe) {
            return self::mostPoints($safe, $trump);
        }

        // En défense : faire couper son partenaire dans une couleur qu'il n'a plus
        if (!$ourContract && !$memory->isVoid($partner, $trump)) {
            foreach ($legal as $card) {
                if (!$card->isTrump($trump) && $memory->isVoid($partner, $card->suit) && [] !== $memory->unseen($trump)) {
                    return self::cheapest(self::ofSuit($legal, $card->suit), $memory);
                }
            }
        }

        // Sinon une petite carte de sa plus longue couleur, sans découvrir un 10 ni entamer à l'atout en défense
        $plain = array_values(array_filter($legal, static fn (Card $card): bool => !$card->isTrump($trump)));
        $uncut = array_values(array_filter($plain, static fn (Card $card): bool => [] === array_filter(
            $opponents,
            static fn (int $player): bool => $memory->isVoid($player, $card->suit) && !$memory->isVoid($player, $trump),
        )));

        return self::cheapest([] !== $uncut ? $uncut : ([] !== $plain ? $plain : $legal), $memory);
    }

    /**
     * @param non-empty-list<Card> $legal
     */
    private function follow(BeloteState $state, int $position, array $legal, BeloteMemory $memory): Card
    {
        $trump = $memory->trump;
        $trick = $state->trick;
        $led = $trick[0]->card->suit;
        $left = 3 - \count($trick);
        $after = 0 === $left ? [] : array_map(static fn (int $step): int => ($position + $step) % 4, range(1, $left));
        $opponentsAfter = array_values(array_filter($after, static fn (int $player): bool => BeloteRules::team($player) !== BeloteRules::team($position)));
        $winner = BeloteRules::winner($trick, $trump);
        $winningCard = self::cardOf($trick, $winner);

        $wins = fn (Card $card): bool => BeloteRules::winner([...$trick, new TrickCard($position, $card)], $trump) === $position;
        $safe = fn (Card $card): bool => !$memory->canBeBeaten($card, $led, $opponentsAfter);

        // Le partenaire tient le pli
        if (BeloteRules::team($winner) === BeloteRules::team($position)) {
            if (!$memory->canBeBeaten($winningCard, $led, $opponentsAfter)) {
                return self::give($legal, $memory, $position);
            }

            // Pli fragile : le reprendre à coup sûr si c'est possible, sinon ne rien y mettre
            $sure = array_values(array_filter($legal, fn (Card $card): bool => $wins($card) && $safe($card)));

            return [] !== $sure ? self::richestEquivalent(self::cheapest($sure, $memory), $legal, $memory) : self::cheapest($legal, $memory);
        }

        $winners = array_values(array_filter($legal, $wins));

        if ([] === $winners) {
            return self::cheapest($legal, $memory);
        }

        $sure = array_values(array_filter($winners, $safe));

        if ([] !== $sure) {
            return self::richestEquivalent(self::cheapest($sure, $memory), $legal, $memory);
        }

        // Personne ne peut garantir le pli : on tente avec la plus forte s'il vaut le coup
        $points = BeloteRules::points($trick, $trump);

        if ($points >= 10) {
            return self::strongest($winners, $trump);
        }

        // Pli sans valeur : on ne le prend que si ça ne coûte rien (une petite coupe, par exemple)
        $cheap = self::cheapest($winners, $memory);

        return 0 === $cheap->points($trump) ? $cheap : self::cheapest($legal, $memory);
    }

    // ---------- Choix d'une carte dans une liste ----------

    /**
     * Charger le pli du partenaire : le plus de points possible, sans y mettre d'atout.
     * Un as ne se donne que si sa couleur est coupée par un adversaire (il ne ferait plus de pli)
     * ou si l'on garde derrière le 10 et le roi de la couleur.
     *
     * @param non-empty-list<Card> $cards
     */
    private static function give(array $cards, BeloteMemory $memory, int $position): Card
    {
        $trump = $memory->trump;
        $plain = array_values(array_filter($cards, static fn (Card $card): bool => !$card->isTrump($trump)));

        if ([] === $plain) {
            return self::cheapest($cards, $memory);
        }

        $opponents = [($position + 1) % 4, ($position + 3) % 4];
        $hand = array_map(static fn (Card $card): string => $card->code(), $cards);
        $canGive = static function (Card $card) use ($opponents, $memory, $hand): bool {
            if (Rank::Ace !== $card->rank) {
                return true;
            }

            foreach ($opponents as $player) {
                if ($memory->isVoid($player, $card->suit)) {
                    return true;
                }
            }

            $ten = (new Card($card->suit, Rank::Ten))->code();
            $king = (new Card($card->suit, Rank::King))->code();

            return \in_array($ten, $hand, true) && \in_array($king, $hand, true);
        };

        $given = array_values(array_filter($plain, $canGive));

        return [] !== $given ? self::mostPoints($given, $trump) : self::cheapest($plain, $memory);
    }

    /**
     * La carte qui coûte le moins : peu de points, hors atout, pas une carte maître, pas la garde d'un 10.
     *
     * @param non-empty-list<Card> $cards
     */
    private static function cheapest(array $cards, BeloteMemory $memory): Card
    {
        $trump = $memory->trump;
        $cost = static function (Card $card) use ($cards, $memory, $trump): float {
            $cost = $card->points($trump) + $card->strength($trump) / 10;

            if ($card->isTrump($trump)) {
                $cost += 20;
            }

            if ($memory->isMaster($card)) {
                $cost += 6;
            }

            $suit = self::ofSuit($cards, $card->suit);
            $guardsTen = 2 === \count($suit) && Rank::Ten !== $card->rank
                && [] !== array_filter($suit, static fn (Card $other): bool => Rank::Ten === $other->rank && !$memory->isMaster($other));

            return $guardsTen ? $cost + 3 : $cost;
        };

        usort($cards, static fn (Card $a, Card $b): int => $cost($a) <=> $cost($b));

        return $cards[0];
    }

    /**
     * Le pli est gagné à coup sûr avec $chosen : si l'on tient une carte plus forte de la même couleur qui lui est
     * « équivalente » (aucune carte cachée entre les deux, comme l'As et le 10 quand on a les deux), on joue celle qui
     * rapporte le plus. Exemple : avec As et 10 de pique, on prend avec l'As ; le 10 devient maître et on ne donne
     * pas un point de plus si la couleur est coupée ensuite.
     *
     * @param non-empty-list<Card> $cards cartes jouables
     */
    private static function richestEquivalent(Card $chosen, array $cards, BeloteMemory $memory): Card
    {
        $trump = $memory->trump;
        $hidden = $memory->unseen($chosen->suit);
        $best = $chosen;

        foreach (self::ofSuit($cards, $chosen->suit) as $card) {
            $low = $chosen->strength($trump);
            $high = $card->strength($trump);

            if ($high <= $low || $card->points($trump) <= $best->points($trump)) {
                continue;
            }

            $between = array_filter($hidden, static fn (Card $other): bool => $other->strength($trump) > $low && $other->strength($trump) < $high);

            if ([] === $between) {
                $best = $card;
            }
        }

        return $best;
    }

    /**
     * @param non-empty-list<Card> $cards
     */
    private static function mostPoints(array $cards, Suit $trump): Card
    {
        usort($cards, static fn (Card $a, Card $b): int => [$b->points($trump), $b->strength($trump)] <=> [$a->points($trump), $a->strength($trump)]);

        return $cards[0];
    }

    /**
     * @param non-empty-list<Card> $cards
     */
    private static function strongest(array $cards, Suit $trump): Card
    {
        usort($cards, static fn (Card $a, Card $b): int => $b->strength($trump) <=> $a->strength($trump));

        return $cards[0];
    }

    /**
     * @param non-empty-list<Card> $cards
     */
    private static function weakest(array $cards, Suit $trump): Card
    {
        usort($cards, static fn (Card $a, Card $b): int => $a->strength($trump) <=> $b->strength($trump));

        return $cards[0];
    }

    /**
     * @param list<Card> $cards
     *
     * @return list<Card>
     */
    private static function ofSuit(array $cards, Suit $suit): array
    {
        return array_values(array_filter($cards, static fn (Card $card): bool => $card->suit === $suit));
    }

    /**
     * @param list<TrickCard> $trick
     */
    private static function cardOf(array $trick, int $position): Card
    {
        foreach ($trick as $played) {
            if ($played->position === $position) {
                return $played->card;
            }
        }

        throw new BeloteException('Carte introuvable dans le pli.');
    }

    /**
     * Tout atout ou sans atout (coinche) : jeu simple. On entame avec sa carte la plus forte ;
     * on prend le pli au plus juste si un adversaire le tient, on charge si le partenaire le tient
     * et que l'on joue en dernier, sinon on se défausse de sa plus petite carte.
     *
     * @param non-empty-list<Card> $legal
     */
    private static function modeCard(BeloteState $state, int $position, array $legal): Card
    {
        $mode = $state->mode;
        $strength = static fn (Card $card): int => ModeRules::strength($card, $mode) * 100 + ModeRules::points($card, $mode);
        $points = static fn (Card $card): int => ModeRules::points($card, $mode) * 100 + ModeRules::strength($card, $mode);
        $sorted = static function (array $cards, \Closure $key): array {
            usort($cards, static fn (Card $a, Card $b): int => $key($a) <=> $key($b));

            return $cards;
        };

        if ([] === $state->trick) {
            return $sorted($legal, $strength)[\count($legal) - 1];
        }

        $winner = ModeRules::winner($state->trick, $mode);

        if (BeloteRules::team($winner) === BeloteRules::team($position)) {
            $cheap = $sorted($legal, $points);

            return 3 === \count($state->trick) ? $cheap[\count($cheap) - 1] : $cheap[0];
        }

        $winning = array_values(array_filter($legal, static function (Card $card) use ($state, $position, $mode): bool {
            return ModeRules::winner([...$state->trick, new TrickCard($position, $card)], $mode) === $position;
        }));

        return [] !== $winning ? $sorted($winning, $strength)[0] : $sorted($legal, $points)[0];
    }
}
