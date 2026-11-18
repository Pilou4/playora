<?php

declare(strict_types=1);

namespace App\Game\Belote;

use Random\Randomizer;

/**
 * Déroulé d'une partie de belote, coup par coup. Chaque action est vérifiée :
 * le navigateur ne fait que proposer, le moteur décide.
 *
 * Belote classique : 3 puis 2 cartes chacun, une carte retournée. 1er tour : prendre la carte retournée
 * ou passer ; 2e tour : choisir une autre couleur ou passer. Le preneur reçoit la carte
 * retournée et 2 cartes, les autres 3. Si personne ne prend, on redistribue.
 *
 * Coinche et contrée : 8 cartes chacun (3, 3 puis 2), pas de carte retournée. Enchères de 80 à 160
 * (par dizaines) capot (250) ou générale (500), avec une couleur d'atout ; chaque enchère doit dépasser la précédente.
 * Un adversaire peut coincher le contrat (×2), l'équipe du preneur surcoincher (×4).
 * Les enchères s'arrêtent après 3 « passe » à la suite (ou une surcoinche) ; 4 « passe » d'entrée : on redistribue.
 *
 * Parties avec annonces : avant de jouer sa première carte, chacun peut déclarer ses annonces
 * (voir Announcements) ; elles sont montrées à la fin du premier pli.
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
    public function start(int $targetPoints, int $dealer, Variant $variant = Variant::Classic, bool $announcements = false): array
    {
        $state = new BeloteState($targetPoints, $dealer, $variant, $announcements);

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
        $this->assertClassic($state);
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

        if (Phase::Auction === $state->phase) {
            return $this->auctionPass($state, $position);
        }

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

    // ---------- Coinche et contrée : enchères ----------

    /**
     * Annoncer un contrat (80 à 160 par dizaines, 250 pour un capot, 500 pour une générale) à une couleur.
     *
     * @throws BeloteException
     */
    public function bid(BeloteState $state, int $position, int $contract, ?Suit $suit, TrumpMode $mode = TrumpMode::Suit): ActionResult
    {
        $this->assertAuction($state, $position);

        if ((TrumpMode::Suit === $mode) !== (null !== $suit)) {
            throw new BeloteException('Choisissez une couleur, ou tout atout, ou sans atout.');
        }

        if (TrumpMode::Suit !== $mode && Variant::Coinche !== $state->variant) {
            throw new BeloteException('Tout atout et sans atout se jouent seulement à la coinche.');
        }

        if (!\in_array($contract, CoincheScorer::contracts(), true)) {
            throw new BeloteException('Annonce impossible : de 80 à 160 par dizaines, ou capot.');
        }

        if (null !== $state->coincher) {
            throw new BeloteException('Le contrat est coinché : on ne peut plus monter.');
        }

        if (null !== $state->contract && $contract <= $state->contract) {
            throw new BeloteException('Annoncez plus que '.$state->contract.'.');
        }

        $state->contract = $contract;
        $state->trump = $suit;
        $state->mode = $mode;
        $state->taker = $position;
        $state->bids[$position] = $contract;
        $state->passes = 0;
        $state->turn = BeloteRules::next($position);

        return new ActionResult([['type' => 'auction', 'position' => $position, 'action' => 'bid', 'contract' => $contract, 'suit' => $suit?->value, 'mode' => $mode->value]]);
    }

    /**
     * Coincher : un adversaire du preneur double le contrat. Les enchères ne peuvent plus monter.
     *
     * @throws BeloteException
     */
    public function coinche(BeloteState $state, int $position): ActionResult
    {
        $this->assertAuction($state, $position);

        if (null === $state->contract || null === $state->taker || BeloteRules::team($position) === BeloteRules::team($state->taker)) {
            throw new BeloteException('On ne coinche que le contrat de l’équipe adverse.');
        }

        if (null !== $state->coincher) {
            throw new BeloteException('Le contrat est déjà coinché.');
        }

        $state->coincher = $position;
        $state->multiplier = 2;
        $state->passes = 0;
        $state->turn = BeloteRules::next($position);

        return new ActionResult([['type' => 'auction', 'position' => $position, 'action' => 'coinche', 'contract' => $state->contract, 'suit' => $state->trump?->value, 'mode' => $state->mode->value]]);
    }

    /**
     * Surcoincher : l'équipe du preneur répond à la coinche (×4). Les enchères s'arrêtent.
     *
     * @throws BeloteException
     */
    public function surcoinche(BeloteState $state, int $position): ActionResult
    {
        $this->assertAuction($state, $position);

        if (null === $state->coincher || 2 !== $state->multiplier || null === $state->taker || BeloteRules::team($position) !== BeloteRules::team($state->taker)) {
            throw new BeloteException('Seule l’équipe du preneur peut surcoincher, après une coinche.');
        }

        $state->multiplier = 4;
        $events = [['type' => 'auction', 'position' => $position, 'action' => 'surcoinche', 'contract' => $state->contract, 'suit' => $state->trump?->value, 'mode' => $state->mode->value]];

        return (new ActionResult($events))->merge($this->closeAuction($state));
    }

    private function auctionPass(BeloteState $state, int $position): ActionResult
    {
        ++$state->passes;
        $state->turn = BeloteRules::next($position);
        $events = [['type' => 'auction', 'position' => $position, 'action' => 'pass', 'contract' => $state->contract, 'suit' => $state->trump?->value, 'mode' => $state->mode->value]];

        if (null === $state->contract) {
            if ($state->passes < 4) {
                return new ActionResult($events);
            }

            // Personne n'annonce : le donneur suivant redistribue
            $events[] = ['type' => 'redeal'];
            $state->dealer = BeloteRules::next($state->dealer);

            return (new ActionResult($events))->merge($this->deal($state));
        }

        return $state->passes < 3 ? new ActionResult($events) : (new ActionResult($events))->merge($this->closeAuction($state));
    }

    /**
     * Fin des enchères : le contrat est connu, le jeu commence à gauche du donneur.
     */
    private function closeAuction(BeloteState $state): ActionResult
    {
        $state->beloteHolder = $this->beloteHolder($state);
        $state->belotePlayed = 0;
        // Tout atout seulement : une belote par Roi et Dame de la même couleur ; sans atout : pas de belote
        $state->modeBelotes = TrumpMode::AllTrumps === $state->mode ? $this->modeBelotes($state) : [];
        $state->phase = Phase::Playing;
        $state->turn = BeloteRules::next($state->dealer);

        return new ActionResult([[
            'type' => 'contract',
            'taker' => $state->taker,
            'trump' => $state->trump?->value,
            'mode' => $state->mode->value,
            'contract' => $state->contract,
            'multiplier' => $state->multiplier,
        ]]);
    }

    private function assertAuction(BeloteState $state, int $position): void
    {
        $this->assertTurn($state, $position, bidding: true);

        if (Phase::Auction !== $state->phase) {
            throw new BeloteException('Pas d’enchères dans cette partie.');
        }
    }

    private function assertClassic(BeloteState $state): void
    {
        if (Phase::Auction === $state->phase) {
            throw new BeloteException('Dans cette partie, on annonce un contrat.');
        }
    }

    /**
     * @throws BeloteException
     */
    public function play(BeloteState $state, int $position, Card $card): ActionResult
    {
        $this->assertTurn($state, $position, bidding: false);
        if (!$state->hasContract()) {
            throw new BeloteException('Aucun atout.');
        }

        $trump = $state->trump;
        $hand = $state->hands[$position];
        $index = $this->indexOf($hand, $card) ?? throw new BeloteException('Cette carte n’est pas dans votre main.');

        if (!$this->contains($state->playableCards($position), $card)) {
            throw new BeloteException(null === $trump ? $this->modeRefusal($hand, $state->trick) : $this->refusal($hand, $state->trick, $trump));
        }

        array_splice($hand, $index, 1);
        $state->hands[$position] = $hand;
        $state->trick[] = new TrickCard($position, $card);
        $state->history[] = new TrickCard($position, $card);
        $events = [['type' => 'played', 'position' => $position, 'card' => $card->code()]];

        if (null !== $trump && $position === $state->beloteHolder && $card->isTrump($trump) && \in_array($card->rank, [Rank::King, Rank::Queen], true)) {
            ++$state->belotePlayed;
            $events[] = ['type' => 'belote', 'position' => $position, 'word' => 1 === $state->belotePlayed ? 'Belote' : 'Rebelote'];
        }

        // Tout atout : belote et rebelote dans chaque couleur où l'on a le Roi et la Dame
        foreach ($state->modeBelotes as $index => $belote) {
            if ($belote['position'] === $position && $belote['suit'] === $card->suit->value && \in_array($card->rank, [Rank::King, Rank::Queen], true)) {
                $played = ++$state->modeBelotes[$index]['played'];
                $events[] = ['type' => 'belote', 'position' => $position, 'word' => 1 === $played ? 'Belote' : 'Rebelote'];
            }
        }

        if (\count($state->trick) < 4) {
            $state->turn = BeloteRules::next($position);

            return new ActionResult($events);
        }

        return (new ActionResult($events))->merge($this->collectTrick($state));
    }

    // ---------- Annonces (parties « avec annonces ») ----------

    /**
     * Les annonces qu'une place peut encore faire : avant de jouer sa première carte, à son tour.
     *
     * @return list<Announcement>
     */
    public function declarable(BeloteState $state, int $position): array
    {
        if (!$state->announcements
            || Phase::Playing !== $state->phase
            || $position !== $state->turn
            || 8 !== \count($state->hands[$position])
            || \array_key_exists($position, $state->declarations)) {
            return [];
        }

        return Announcements::inHand($state->hands[$position]);
    }

    /**
     * Déclarer ses annonces. Elles sont montrées à tous à la fin du premier pli.
     *
     * @throws BeloteException
     */
    public function declare(BeloteState $state, int $position): ActionResult
    {
        $announcements = $this->declarable($state, $position);

        if ([] === $announcements) {
            throw new BeloteException('Vous n’avez pas d’annonce à faire.');
        }

        $state->declarations[$position] = $announcements;

        return new ActionResult([[
            'type' => 'declared',
            'position' => $position,
            'names' => array_map(static fn (Announcement $a): string => $a->name(), $announcements),
        ]]);
    }

    /**
     * Fin du premier pli : on montre les annonces et on désigne l'équipe qui les marque.
     */
    private function resolveAnnouncements(BeloteState $state): ActionResult
    {
        if ([] === $state->declarations) {
            return new ActionResult();
        }

        $team = Announcements::winningTeam($state->declarations, $state->trump, $state->dealer, $state->variant->hasAuction());
        $state->announceTeam = $team;
        $state->announcePoints = null === $team ? 0 : Announcements::teamPoints($state->declarations, $team);
        $shown = [];

        foreach ($state->declarations as $position => $announcements) {
            $shown[$position] = array_map(static fn (Announcement $a): array => $a->toArray(), $announcements);
        }

        return new ActionResult([[
            'type' => 'announcements',
            'team' => $team,
            'points' => $state->announcePoints,
            'declarations' => $shown,
        ]]);
    }

    private function collectTrick(BeloteState $state): ActionResult
    {
        $winner = $state->trickWinner($state->trick);
        $team = BeloteRules::team($winner);
        $points = $state->trickPoints($state->trick);
        $last = [] === $state->hands[0];

        if ($last) {
            $points += BeloteScorer::LAST_TRICK;
        }

        $state->cardPoints[$team] += $points;
        ++$state->tricksWon[$team];
        ++$state->playerTricks[$winner];
        ++$state->dealTricks[$winner];
        $state->playerPoints[$winner] += $points;
        $state->lastTrick = $state->trick;
        $state->trick = [];
        $state->turn = $winner;
        $result = new ActionResult([['type' => 'trick', 'winner' => $winner, 'points' => $points, 'last' => $last]]);

        if (4 === \count($state->history)) {
            $result = $result->merge($this->resolveAnnouncements($state));
        }

        return $last ? $result->merge($this->endDeal($state)) : $result;
    }

    private function endDeal(BeloteState $state): ActionResult
    {
        $belote = $state->belotePoints();
        $beloteTeam = match (true) {
            null !== $state->beloteHolder => BeloteRules::team($state->beloteHolder),
            $belote[0] > 0 && 0 === $belote[1] => 0,
            $belote[1] > 0 && 0 === $belote[0] => 1,
            default => null,
        };
        $announces = [0, 0];

        if (null !== $state->announceTeam) {
            $announces[$state->announceTeam] = $state->announcePoints;
        }
        $deal = $state->variant->hasAuction()
            ? CoincheScorer::score(
                $state->dealNumber,
                $state->dealer,
                $state->taker ?? 0,
                $state->trump,
                $state->contract ?? CoincheScorer::MIN_CONTRACT,
                $state->multiplier,
                $state->cardPoints,
                $state->tricksWon,
                $beloteTeam,
                $state->dealTricks[$state->taker ?? 0],
                $announces,
                $state->mode,
                $belote,
            )
            : BeloteScorer::score(
                $state->dealNumber,
                $state->dealer,
                $state->taker ?? 0,
                $state->trump,
                $state->cardPoints,
                $state->tricksWon,
                $beloteTeam,
                $announces,
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

        $auction = $state->variant->hasAuction();

        // Classique : 3 cartes puis 2 et une carte retournée ; coinche : 3, 3 puis 2 (toute la main). À gauche du donneur d'abord.
        foreach ($auction ? [3, 3, 2] : [3, 2] as $count) {
            foreach ($this->fromDealer($state) as $player) {
                $state->hands[$player] = [...$state->hands[$player], ...array_splice($deck, 0, $count)];
            }
        }

        $state->turnedCard = $auction ? null : array_shift($deck);
        $state->stock = $deck;
        ++$state->dealNumber;
        $state->phase = $auction ? Phase::Auction : Phase::FirstBidding;
        $state->contract = null;
        $state->multiplier = 1;
        $state->coincher = null;
        $state->bids = [];
        $state->turn = BeloteRules::next($state->dealer);
        $state->passes = 0;
        $state->taker = null;
        $state->trump = null;
        $state->mode = TrumpMode::Suit;
        $state->beloteHolder = null;
        $state->belotePlayed = 0;
        $state->modeBelotes = [];
        $state->trick = [];
        $state->lastTrick = [];
        $state->history = [];
        $state->cardPoints = [0, 0];
        $state->tricksWon = [0, 0];
        $state->dealTricks = [0, 0, 0, 0];
        $state->declarations = [];
        $state->announceTeam = null;
        $state->announcePoints = 0;

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

    /**
     * @return list<array{position: int, suit: string, played: int}>
     */
    private function modeBelotes(BeloteState $state): array
    {
        $belotes = [];

        foreach ($state->hands as $position => $hand) {
            foreach (Suit::cases() as $suit) {
                $honours = array_filter($hand, static fn (Card $card): bool => $card->suit === $suit && \in_array($card->rank, [Rank::King, Rank::Queen], true));

                if (2 === \count($honours)) {
                    $belotes[] = ['position' => $position, 'suit' => $suit->value, 'played' => 0];
                }
            }
        }

        return $belotes;
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
     * @param list<Card> $cards
     */
    private function contains(array $cards, Card $card): bool
    {
        return null !== $this->indexOf($cards, $card);
    }

    /**
     * Tout atout ou sans atout : on doit fournir (et monter en tout atout).
     *
     * @param list<Card>      $hand
     * @param list<TrickCard> $trick
     */
    private function modeRefusal(array $hand, array $trick): string
    {
        return [] !== $trick && [] !== BeloteRules::ofSuit($hand, $trick[0]->card->suit)
            ? 'Vous devez fournir la couleur demandée, en montant si vous le pouvez.'
            : 'Cette carte ne peut pas être jouée.';
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
