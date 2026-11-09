<?php

declare(strict_types=1);

namespace App\Game\Belote;

use Random\Randomizer;

/**
 * Déroulé d'une partie de belote, coup par coup. Chaque action est vérifiée :
 * le navigateur ne fait que proposer, le moteur décide.
 *
 * Donne : 3 puis 2 cartes chacun, une carte retournée. 1er tour : prendre la carte retournée
 * ou passer ; 2e tour : choisir une autre couleur ou passer. Le preneur reçoit la carte
 * retournée et 2 cartes, les autres 3. Si personne ne prend, on redistribue.
 */
final readonly class BeloteEngine
{
    public function __construct(
        private Randomizer $randomizer = new Randomizer(),
    ) {
    }

    /**
     * @return array{BeloteState, ActionResult}
     */
    public function start(int $targetPoints, int $dealer): array
    {
        $state = new BeloteState($targetPoints, $dealer);

        return [$state, $this->deal($state)];
    }

    /**
     * Prendre : au 1er tour, à la couleur de la carte retournée ; au 2e, à une autre couleur.
     *
     * @throws BeloteException
     */
    public function take(BeloteState $state, int $position, ?Suit $suit = null): ActionResult
    {
        $this->assertTurn($state, $position, bidding: true);
        $turned = $state->turnedCard ?? throw new BeloteException('Aucune carte retournée.');

        if (Phase::FirstBidding === $state->phase) {
            if (null !== $suit && $suit !== $turned->suit) {
                throw new BeloteException('Au premier tour, on prend à la couleur retournée.');
            }

            $suit = $turned->suit;
        } elseif (null === $suit || $suit === $turned->suit) {
            throw new BeloteException('Au second tour, choisissez une autre couleur.');
        }

        $state->taker = $position;
        $state->trump = $suit;
        $events = [['type' => 'bid', 'position' => $position, 'take' => true, 'suit' => $suit->value]];

        // Le preneur reçoit la carte retournée et 2 cartes ; les autres en reçoivent 3
        $state->hands[$position][] = $turned;
        $state->turnedCard = null;
        $received = [];

        foreach ($this->fromDealer($state) as $player) {
            $count = $player === $position ? 2 : 3;
            $cards = array_splice($state->stock, 0, $count);
            $state->hands[$player] = [...$state->hands[$player], ...$cards];
            $received[$player] = $cards;
        }

        $state->beloteHolder = $this->beloteHolder($state);
        $state->belotePlayed = 0;
        $state->phase = Phase::Playing;
        $state->turn = BeloteRules::next($state->dealer);
        $events[] = ['type' => 'completed', 'taker' => $position, 'trump' => $suit->value, 'turned' => $turned->code(), 'cards' => $this->codes($received)];

        return new ActionResult($events);
    }

    /**
     * @throws BeloteException
     */
    public function pass(BeloteState $state, int $position): ActionResult
    {
        $this->assertTurn($state, $position, bidding: true);
        $events = [['type' => 'bid', 'position' => $position, 'take' => false, 'suit' => null]];
        ++$state->passes;
        $state->turn = BeloteRules::next($position);

        if ($state->passes < 4) {
            return new ActionResult($events);
        }

        if (Phase::FirstBidding === $state->phase) {
            $state->phase = Phase::SecondBidding;
            $state->passes = 0;
            $events[] = ['type' => 'secondRound'];

            return new ActionResult($events);
        }

        // Personne ne prend : le donneur suivant redistribue
        $events[] = ['type' => 'redeal'];
        $state->dealer = BeloteRules::next($state->dealer);

        return (new ActionResult($events))->merge($this->deal($state));
    }

    /**
     * @throws BeloteException
     */
    public function play(BeloteState $state, int $position, Card $card): ActionResult
    {
        $this->assertTurn($state, $position, bidding: false);
        $trump = $state->trump ?? throw new BeloteException('Aucun atout.');
        $hand = $state->hands[$position];
        $index = $this->indexOf($hand, $card) ?? throw new BeloteException('Cette carte n’est pas dans votre main.');

        if (!BeloteRules::isPlayable($card, $hand, $state->trick, $trump, $position)) {
            throw new BeloteException($this->refusal($hand, $state->trick, $trump));
        }

        array_splice($hand, $index, 1);
        $state->hands[$position] = $hand;
        $state->trick[] = new TrickCard($position, $card);
        $state->history[] = new TrickCard($position, $card);
        $events = [['type' => 'played', 'position' => $position, 'card' => $card->code()]];

        if ($position === $state->beloteHolder && $card->isTrump($trump) && \in_array($card->rank, [Rank::King, Rank::Queen], true)) {
            ++$state->belotePlayed;
            $events[] = ['type' => 'belote', 'position' => $position, 'word' => 1 === $state->belotePlayed ? 'Belote' : 'Rebelote'];
        }

        if (\count($state->trick) < 4) {
            $state->turn = BeloteRules::next($position);

            return new ActionResult($events);
        }

        return (new ActionResult($events))->merge($this->collectTrick($state, $trump));
    }

    private function collectTrick(BeloteState $state, Suit $trump): ActionResult
    {
        $winner = BeloteRules::winner($state->trick, $trump);
        $team = BeloteRules::team($winner);
        $points = BeloteRules::points($state->trick, $trump);
        $last = [] === $state->hands[0];

        if ($last) {
            $points += BeloteScorer::LAST_TRICK;
        }

        $state->cardPoints[$team] += $points;
        ++$state->tricksWon[$team];
        ++$state->playerTricks[$winner];
        $state->playerPoints[$winner] += $points;
        $state->lastTrick = $state->trick;
        $state->trick = [];
        $state->turn = $winner;
        $result = new ActionResult([['type' => 'trick', 'winner' => $winner, 'points' => $points, 'last' => $last]]);

        return $last ? $result->merge($this->endDeal($state, $trump)) : $result;
    }

    private function endDeal(BeloteState $state, Suit $trump): ActionResult
    {
        $deal = BeloteScorer::score(
            $state->dealNumber,
            $state->dealer,
            $state->taker ?? 0,
            $trump,
            $state->cardPoints,
            $state->tricksWon,
            null === $state->beloteHolder ? null : BeloteRules::team($state->beloteHolder),
            $state->pending,
        );

        $state->scores[0] += $deal->scored[0];
        $state->scores[1] += $deal->scored[1];
        $state->dealScores[] = $deal->scored;
        $state->pending = $deal->pendingAfter;
        $events = [['type' => 'dealEnd', 'result' => $deal->toArray(), 'scores' => $state->scores]];

        $best = max($state->scores);

        if ($best >= $state->targetPoints && $state->scores[0] !== $state->scores[1]) {
            $state->phase = Phase::Finished;
            $state->winnerTeam = $state->scores[0] > $state->scores[1] ? 0 : 1;
            $events[] = ['type' => 'gameEnd', 'winner' => $state->winnerTeam, 'scores' => $state->scores];

            return new ActionResult($events, [$deal]);
        }

        $state->dealer = BeloteRules::next($state->dealer);

        return (new ActionResult($events, [$deal]))->merge($this->deal($state));
    }

    private function deal(BeloteState $state): ActionResult
    {
        $deck = Deck::shuffled($this->randomizer);
        $state->hands = [[], [], [], []];

        // 3 cartes puis 2, en commençant à gauche du donneur
        foreach ([3, 2] as $count) {
            foreach ($this->fromDealer($state) as $player) {
                $state->hands[$player] = [...$state->hands[$player], ...array_splice($deck, 0, $count)];
            }
        }

        $state->turnedCard = array_shift($deck);
        $state->stock = $deck;
        ++$state->dealNumber;
        $state->phase = Phase::FirstBidding;
        $state->turn = BeloteRules::next($state->dealer);
        $state->passes = 0;
        $state->taker = null;
        $state->trump = null;
        $state->beloteHolder = null;
        $state->belotePlayed = 0;
        $state->trick = [];
        $state->lastTrick = [];
        $state->history = [];
        $state->cardPoints = [0, 0];
        $state->tricksWon = [0, 0];

        return new ActionResult([[
            'type' => 'dealt',
            'deal' => $state->dealNumber,
            'dealer' => $state->dealer,
            'turned' => $state->turnedCard?->code(),
            'cards' => $this->codes($state->hands),
        ]]);
    }

    /**
     * @return list<int>
     */
    private function fromDealer(BeloteState $state): array
    {
        return array_map(static fn (int $offset): int => ($state->dealer + $offset) % 4, [1, 2, 3, 4]);
    }

    private function beloteHolder(BeloteState $state): ?int
    {
        foreach ($state->hands as $position => $hand) {
            $honours = array_filter($hand, static fn (Card $card): bool => $card->suit === $state->trump && \in_array($card->rank, [Rank::King, Rank::Queen], true));

            if (2 === \count($honours)) {
                return $position;
            }
        }

        return null;
    }

    private function assertTurn(BeloteState $state, int $position, bool $bidding): void
    {
        if (Phase::Finished === $state->phase) {
            throw new BeloteException('La partie est terminée.');
        }

        if ($bidding !== $state->phase->isBidding()) {
            throw new BeloteException($bidding ? 'Les enchères sont terminées.' : 'Les enchères ne sont pas terminées.');
        }

        if ($position !== $state->turn) {
            throw new BeloteException('Ce n’est pas votre tour.');
        }
    }

    /**
     * @param list<Card> $hand
     */
    private function indexOf(array $hand, Card $card): ?int
    {
        foreach ($hand as $index => $held) {
            if ($held->equals($card)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<Card>      $hand
     * @param list<TrickCard> $trick
     */
    private function refusal(array $hand, array $trick, Suit $trump): string
    {
        $led = $trick[0]->card->suit;

        return match (true) {
            [] !== BeloteRules::ofSuit($hand, $led) && $led === $trump => 'Vous devez monter à l’atout.',
            [] !== BeloteRules::ofSuit($hand, $led) => 'Vous devez fournir à '.mb_strtolower($led->label()).'.',
            default => 'Vous devez couper à l’atout.',
        };
    }

    /**
     * @param array<int, list<Card>> $hands
     *
     * @return array<int, list<string>>
     */
    private function codes(array $hands): array
    {
        return array_map(static fn (array $cards): array => array_map(static fn (Card $card): string => $card->code(), $cards), $hands);
    }
}
