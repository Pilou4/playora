<?php

declare(strict_types=1);

namespace App\Game\Tarot;

use Random\Randomizer;

/**
 * Déroulé d'une partie de tarot à 4 (règles FFT, comme sur GameDuell), coup par coup.
 * Chaque action est vérifiée : le navigateur ne fait que proposer, le moteur décide.
 *
 * Donne : 18 cartes chacun, 3 par 3, et 6 au chien (jamais la première ni la dernière carte du paquet).
 * Petit sec (le Petit seul atout, sans l'Excuse) : la donne est annulée et le donneur suivant redistribue.
 * Enchères : à partir du joueur suivant le donneur, chacun parle une fois : passe, ou plus haut que l'enchère en cours.
 * Personne ne prend : le donneur suivant redistribue.
 * Petite et garde : le chien est montré, le preneur le prend et écarte 6 cartes (ni Roi ni bout ;
 * des atouts seulement s'il ne peut pas faire autrement, et ils sont montrés).
 * Garde sans : le chien compte pour le preneur, sans être vu ; garde contre : il compte pour la défense.
 * À 3 joueurs : 24 cartes chacun (4 par 4) ; à 5 : 15 cartes chacun et 3 au chien, et le preneur appelle un Roi :
 * celui qui l'a joue avec lui sans le dire (s'il appelle un Roi qu'il a, ou qui est au chien, il joue seul).
 * Le preneur peut annoncer le chelem avant la première carte : il entame alors.
 * Avant de jouer sa première carte, chacun peut annoncer une poignée (10, 13 ou 15 atouts, l'Excuse comprise).
 */
final readonly class TarotEngine
{
    public function __construct(
        private Randomizer $randomizer = new Randomizer(),
    ) {
    }

    /**
     * @return array{TarotState, TarotActionResult}
     */
    public function start(int $totalDeals, int $dealer, int $players = 4): array
    {
        if (!\in_array($players, TarotRules::PLAYER_COUNTS, true)) {
            throw new TarotException('Le tarot se joue à 3, 4 ou 5.');
        }

        $state = new TarotState($totalDeals, $dealer, $players);

        return [$state, $this->deal($state)];
    }

    // ---------- Enchères ----------

    /**
     * Passer (contrat null) ou annoncer un contrat plus fort que le précédent.
     *
     * @throws TarotException
     */
    public function bid(TarotState $state, int $position, ?TarotContract $contract): TarotActionResult
    {
        $this->assertTurn($state, $position, TarotPhase::Bidding);

        if (null !== $contract && null !== $state->contract && $contract->level() <= $state->contract->level()) {
            throw new TarotException('Annoncez plus fort que '.mb_strtolower($state->contract->label()).'.');
        }

        $state->bids[$position] = $contract?->value ?? 'passe';
        $events = [['type' => 'bid', 'position' => $position, 'contract' => $contract?->value]];

        if (null !== $contract) {
            $state->contract = $contract;
            $state->taker = $position;
        }

        $state->turn = $state->next($position);

        if (\count($state->bids) < $state->players) {
            return new TarotActionResult($events);
        }

        if (null === $state->taker) {
            $events[] = ['type' => 'redeal', 'reason' => 'passe'];
            $state->dealer = $state->next($state->dealer);

            return (new TarotActionResult($events))->merge($this->deal($state, redeal: true));
        }

        return (new TarotActionResult($events))->merge($this->closeBidding($state));
    }

    private function closeBidding(TarotState $state): TarotActionResult
    {
        $taker = $state->taker ?? throw new TarotException('Aucun preneur.');
        $contract = $state->contract ?? throw new TarotException('Aucun contrat.');

        // À 5 joueurs, le preneur appelle d'abord un Roi
        if (5 === $state->players) {
            $state->phase = TarotPhase::Call;
            $state->turn = $taker;

            return new TarotActionResult([['type' => 'contract', 'taker' => $taker, 'contract' => $contract->value]]);
        }

        return $this->openDog($state, ['type' => 'contract', 'taker' => $taker, 'contract' => $contract->value]);
    }

    /**
     * À 5 joueurs : les cartes que le preneur peut appeler (les Rois ; s'il a les 4 Rois, les Dames ;
     * s'il a aussi les 4 Dames, les Cavaliers).
     *
     * @return list<TarotCard>
     */
    public function callable(TarotState $state): array
    {
        if (TarotPhase::Call !== $state->phase || null === $state->taker) {
            return [];
        }

        $hand = $state->hands[$state->taker];

        foreach ([TarotCard::KING, TarotCard::QUEEN, TarotCard::KNIGHT] as $rank) {
            $held = array_filter($hand, static fn (TarotCard $card): bool => $card->suit->isColour() && $card->rank === $rank);

            if (\count($held) < 4) {
                return array_map(static fn (TarotSuit $suit): TarotCard => new TarotCard($suit, $rank), TarotSuit::colours());
            }
        }

        return array_map(static fn (TarotSuit $suit): TarotCard => new TarotCard($suit, TarotCard::JACK), TarotSuit::colours());
    }

    /**
     * À 5 joueurs : le preneur appelle une carte ; son partenaire est celui qui l'a (en secret).
     *
     * @throws TarotException
     */
    public function call(TarotState $state, int $position, TarotCard $card): TarotActionResult
    {
        $this->assertTurn($state, $position, TarotPhase::Call);

        if (null === $this->indexOf($this->callable($state), $card)) {
            throw new TarotException('Appelez un Roi (une Dame si vous avez les quatre Rois).');
        }

        $state->called = $card;
        $state->partner = null;

        foreach ($state->hands as $holder => $hand) {
            if ($holder !== $position && null !== $this->indexOf($hand, $card)) {
                $state->partner = $holder;
            }
        }

        return $this->openDog($state, ['type' => 'called', 'taker' => $position, 'card' => $card->code(), 'contract' => $state->contract?->value]);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function openDog(TarotState $state, array $event): TarotActionResult
    {
        $taker = $state->taker ?? throw new TarotException('Aucun preneur.');
        $contract = $state->contract ?? throw new TarotException('Aucun contrat.');

        if ($contract->usesDog()) {
            // Le chien est montré à tous puis rejoint la main du preneur
            $event['dog'] = $this->codes($state->dog);
            $state->shownDog = $state->dog;
            $state->hands[$taker] = [...$state->hands[$taker], ...$state->dog];
            $state->dog = [];
            $state->phase = TarotPhase::Dog;
            $state->turn = $taker;

            return new TarotActionResult([$event]);
        }

        // Garde sans, garde contre : le chien reste caché, de côté
        $state->discard = $state->dog;
        $state->dog = [];
        $state->phase = TarotPhase::Playing;
        $state->turn = $state->next($state->dealer);

        return new TarotActionResult([$event]);
    }

    /**
     * L'écart du preneur : autant de cartes que le chien (6, ou 3 à 5 joueurs).
     *
     * @param list<TarotCard> $cards
     *
     * @throws TarotException
     */
    public function discard(TarotState $state, int $position, array $cards): TarotActionResult
    {
        $this->assertTurn($state, $position, TarotPhase::Dog);

        $dog = TarotRules::dogSize($state->players);

        if ($dog !== \count($cards)) {
            throw new TarotException('Choisissez '.$dog.' cartes à écarter.');
        }

        $hand = $state->hands[$position];
        [$plain] = TarotRules::discardable($hand);
        $shown = [];

        foreach ($cards as $index => $card) {
            foreach (\array_slice($cards, $index + 1) as $other) {
                if ($other->equals($card)) {
                    throw new TarotException('Une même carte est choisie deux fois.');
                }
            }

            if (null === $this->indexOf($hand, $card)) {
                throw new TarotException('Cette carte n’est pas dans votre main.');
            }

            if ($card->isKing() || $card->isBout()) {
                throw new TarotException('On n’écarte ni Roi ni bout.');
            }

            if ($card->isTrump()) {
                $shown[] = $card;
            }
        }

        // Des atouts seulement si les cartes de couleur (sans les Rois) ne suffisent pas
        if (\count($shown) > max(0, $dog - \count($plain))) {
            throw new TarotException('On n’écarte des atouts que si l’on ne peut pas faire autrement.');
        }

        foreach ($cards as $card) {
            array_splice($hand, (int) $this->indexOf($hand, $card), 1);
        }

        $state->hands[$position] = $hand;
        $state->discard = $cards;
        $state->phase = TarotPhase::Playing;
        $state->turn = $state->chelemAnnounced ? $position : $state->next($state->dealer);

        return new TarotActionResult([['type' => 'discarded', 'position' => $position, 'trumps' => $this->codes($shown)]]);
    }

    /**
     * Le preneur annonce le chelem (tous les plis) avant la première carte : il entame.
     *
     * @throws TarotException
     */
    public function chelem(TarotState $state, int $position): TarotActionResult
    {
        $beforeFirstCard = TarotPhase::Dog === $state->phase || (TarotPhase::Playing === $state->phase && [] === $state->history);

        if ($position !== $state->taker || !$beforeFirstCard || $state->chelemAnnounced) {
            throw new TarotException('Seul le preneur annonce le chelem, avant la première carte.');
        }

        $state->chelemAnnounced = true;

        if (TarotPhase::Playing === $state->phase) {
            $state->turn = $position;
        }

        return new TarotActionResult([['type' => 'chelem', 'position' => $position]]);
    }

    // ---------- Poignée ----------

    /**
     * Les poignées qu'une place peut annoncer maintenant (à son tour, avant sa première carte).
     *
     * @return list<Poignee>
     */
    public function poigneesFor(TarotState $state, int $position): array
    {
        if (TarotPhase::Playing !== $state->phase || $position !== $state->turn
            || TarotRules::handSize($state->players) !== \count($state->hands[$position]) || isset($state->poignees[$position])) {
            return [];
        }

        $count = TarotRules::poigneeTrumps($state->hands[$position]);

        return array_values(array_filter(Poignee::cases(), static fn (Poignee $poignee): bool => $count >= $poignee->trumps($state->players)));
    }

    /**
     * @throws TarotException
     */
    public function poignee(TarotState $state, int $position, Poignee $poignee): TarotActionResult
    {
        if (!\in_array($poignee, $this->poigneesFor($state, $position), true)) {
            throw new TarotException('Poignée impossible : il faut assez d’atouts, avant de jouer sa première carte.');
        }

        // On montre le nombre d'atouts demandé (l'Excuse seulement s'il en manque)
        $trumps = TarotRules::ofSuit($state->hands[$position], TarotSuit::Trumps);
        usort($trumps, static fn (TarotCard $a, TarotCard $b): int => $a->rank <=> $b->rank);
        $shown = \array_slice($trumps, 0, $poignee->trumps($state->players));

        if (\count($shown) < $poignee->trumps($state->players)) {
            $shown[] = new TarotCard(TarotSuit::Excuse, 0);
        }

        $state->poignees[$position] = $poignee;

        return new TarotActionResult([['type' => 'poignee', 'position' => $position, 'poignee' => $poignee->value, 'cards' => $this->codes($shown)]]);
    }

    // ---------- Jeu de la carte ----------

    /**
     * @throws TarotException
     */
    public function play(TarotState $state, int $position, TarotCard $card): TarotActionResult
    {
        $this->assertTurn($state, $position, TarotPhase::Playing);
        $hand = $state->hands[$position];
        $index = $this->indexOf($hand, $card) ?? throw new TarotException('Cette carte n’est pas dans votre main.');

        if (!TarotRules::isPlayable($card, $hand, $state->trick)) {
            throw new TarotException($this->refusal($hand, $state->trick));
        }

        array_splice($hand, $index, 1);
        $state->hands[$position] = $hand;
        $state->trick[] = new TrickCard($position, $card);
        $state->history[] = new TrickCard($position, $card);
        $result = new TarotActionResult([['type' => 'played', 'position' => $position, 'card' => $card->code()]]);

        // À 5 joueurs : le Roi appelé sort, tout le monde sait qui est le partenaire
        if (null !== $state->called && $card->equals($state->called)) {
            $result = $result->merge(new TarotActionResult([['type' => 'partner', 'position' => $position, 'taker' => $state->taker, 'alone' => $position === $state->taker]]));
        }

        if (\count($state->trick) < $state->players) {
            $state->turn = $state->next($position);

            return $result;
        }

        return $result->merge($this->collectTrick($state));
    }

    private function collectTrick(TarotState $state): TarotActionResult
    {
        $last = [] === $state->hands[0];
        $winner = TarotRules::winner($state->trick);

        // Chelem : l'Excuse jouée au dernier pli par le camp qui a fait tous les autres plis le remporte
        foreach ($state->trick as $played) {
            $camp = $state->camp($played->position);

            if ($last && $played->card->isExcuse() && 0 === $state->tricksTaken[1 - $camp] && $state->tricksTaken[$camp] > 0) {
                $winner = $played->position;
            }
        }

        $winnerCamp = $state->camp($winner);

        foreach ($state->trick as $played) {
            // L'Excuse reste au camp qui l'a jouée ; il donnera une petite carte en échange
            $camp = $played->card->isExcuse() ? $state->camp($played->position) : $winnerCamp;
            $state->campCards[$camp][] = $played->card;

            if ($played->card->isExcuse() && $camp !== $winnerCamp) {
                $state->excuseDebt = $camp;
            }

            if ($last && $played->card->isPetit()) {
                $state->petitAuBout = $winnerCamp;
            }
        }

        ++$state->tricksTaken[$winnerCamp];
        $state->lastTrick = $state->trick;
        $state->trick = [];
        $state->turn = $winner;
        $result = new TarotActionResult([['type' => 'trick', 'winner' => $winner, 'last' => $last]]);

        return $last ? $result->merge($this->endDeal($state)) : $result;
    }

    private function endDeal(TarotState $state): TarotActionResult
    {
        $contract = $state->contract ?? throw new TarotException('Aucun contrat.');
        $taker = $state->taker ?? throw new TarotException('Aucun preneur.');

        // Petite, garde : l'écart compte pour le preneur ; garde sans : le chien aussi ; garde contre : pour la défense
        $takerCards = TarotContract::GardeContre === $contract ? $state->campCards[0] : [...$state->campCards[0], ...$state->discard];
        $halfPoints = TarotRules::halfPoints($takerCards);
        $halfPoints += match ($state->excuseDebt) {
            0 => -1,
            1 => 1,
            default => 0,
        };

        $deal = TarotScorer::score(
            $state->dealNumber,
            $state->dealer,
            $taker,
            $contract,
            $halfPoints,
            TarotRules::bouts($takerCards),
            $state->petitAuBout,
            $state->poignees,
            $state->chelemAnnounced,
            $state->tricksTaken,
            $state->players,
            $state->partner,
        );

        foreach ($deal->scored as $position => $points) {
            $state->scores[$position] += $points;
        }

        $state->dealScores[] = $deal->scored;
        $events = [['type' => 'dealEnd', 'result' => $deal->toArray(), 'scores' => $state->scores]];

        if ($state->dealNumber >= $state->totalDeals) {
            $state->phase = TarotPhase::Finished;
            $state->winner = (int) array_search(max($state->scores), $state->scores, true);
            $events[] = ['type' => 'gameEnd', 'winner' => $state->winner, 'scores' => $state->scores];

            return new TarotActionResult($events, [$deal]);
        }

        $state->dealer = $state->next($state->dealer);

        return (new TarotActionResult($events, [$deal]))->merge($this->deal($state));
    }

    /**
     * @param bool $redeal personne n'a pris : la même donne est redistribuée (elle ne compte pas)
     */
    private function deal(TarotState $state, bool $redeal = false): TarotActionResult
    {
        $events = [];

        // Petit sec : la donne est annulée, le donneur suivant redistribue (au plus quelques fois de suite)
        for ($attempt = 0; ; ++$attempt) {
            $deck = TarotDeck::shuffled($this->randomizer);
            $dogSize = TarotRules::dogSize($state->players);
            // Ni la première ni la dernière carte au chien
            $dog = array_splice($deck, 1 + $this->randomizer->getInt(0, \count($deck) - $dogSize - 2), $dogSize);
            $hands = array_fill(0, $state->players, []);
            // 3 par 3 (4 par 4 à 3 joueurs), en commençant par le joueur suivant le donneur
            $packet = 3 === $state->players ? 4 : 3;

            for ($offset = 1; [] !== $deck; $offset = $offset % $state->players + 1) {
                $player = ($state->dealer + $offset) % $state->players;
                $hands[$player] = [...$hands[$player], ...array_splice($deck, 0, $packet)];
            }

            $petitSec = null;

            foreach ($hands as $position => $hand) {
                $trumps = TarotRules::ofSuit($hand, TarotSuit::Trumps);
                $excuse = TarotRules::ofSuit($hand, TarotSuit::Excuse);

                if (1 === \count($trumps) && $trumps[0]->isPetit() && [] === $excuse) {
                    $petitSec = $position;
                }
            }

            if (null === $petitSec || $attempt >= 5) {
                break;
            }

            $events[] = ['type' => 'redeal', 'reason' => 'petit-sec', 'position' => $petitSec];
            $state->dealer = $state->next($state->dealer);
        }

        $state->hands = $hands;
        $state->dog = $dog;
        $state->dealNumber += $redeal ? 0 : 1;
        $state->phase = TarotPhase::Bidding;
        $state->turn = $state->next($state->dealer);
        $state->taker = null;
        $state->contract = null;
        $state->called = null;
        $state->partner = null;
        $state->bids = [];
        $state->chelemAnnounced = false;
        $state->poignees = [];
        $state->discard = [];
        $state->shownDog = [];
        $state->trick = [];
        $state->lastTrick = [];
        $state->history = [];
        $state->campCards = [[], []];
        $state->tricksTaken = [0, 0];
        $state->excuseDebt = null;
        $state->petitAuBout = null;
        $events[] = [
            'type' => 'dealt',
            'deal' => $state->dealNumber,
            'dealer' => $state->dealer,
            'cards' => array_map(fn (array $hand): array => $this->codes($hand), $state->hands),
        ];

        return new TarotActionResult($events);
    }

    private function assertTurn(TarotState $state, int $position, TarotPhase $phase): void
    {
        if (TarotPhase::Finished === $state->phase) {
            throw new TarotException('La partie est terminée.');
        }

        if ($phase !== $state->phase) {
            throw new TarotException(match ($phase) {
                TarotPhase::Bidding => 'Les enchères sont terminées.',
                TarotPhase::Dog => 'Ce n’est pas le moment de faire l’écart.',
                TarotPhase::Call => 'Ce n’est pas le moment d’appeler un Roi.',
                default => 'Les enchères ne sont pas terminées.',
            });
        }

        if ($position !== $state->turn) {
            throw new TarotException('Ce n’est pas votre tour.');
        }
    }

    /**
     * @param list<TarotCard>  $hand
     * @param list<TrickCard>  $trick
     */
    private function refusal(array $hand, array $trick): string
    {
        $led = TarotRules::ledSuit($trick);

        return match (true) {
            null !== $led && $led->isColour() && [] !== TarotRules::ofSuit($hand, $led) => 'Vous devez fournir la couleur demandée.',
            [] !== TarotRules::ofSuit($hand, TarotSuit::Trumps) && TarotSuit::Trumps === $led => 'Vous devez monter à l’atout si vous le pouvez.',
            [] !== TarotRules::ofSuit($hand, TarotSuit::Trumps) => 'Vous devez couper (et surcouper si vous le pouvez).',
            default => 'Cette carte ne peut pas être jouée.',
        };
    }

    /**
     * @param list<TarotCard> $cards
     */
    private function indexOf(array $cards, TarotCard $card): ?int
    {
        foreach ($cards as $index => $held) {
            if ($held->equals($card)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<TarotCard> $cards
     *
     * @return list<string>
     */
    private function codes(array $cards): array
    {
        return array_map(static fn (TarotCard $card): string => $card->code(), $cards);
    }
}
