<?php

declare(strict_types=1);

namespace App\Game\Tarot;

/**
 * Tout l'état d'une partie de tarot, enregistré en JSON. Seul le serveur le voit en entier :
 * chaque joueur ne reçoit que sa main (le chien n'est montré qu'en petite et en garde).
 *
 * Camps : 0 le preneur (et à 5 joueurs son partenaire, celui qui a le Roi appelé), 1 la défense.
 */
final class TarotState
{
    public TarotPhase $phase = TarotPhase::Bidding;
    public int $dealNumber = 0;
    public int $turn = 0;
    public ?int $taker = null;
    public ?TarotContract $contract = null;
    /** À 5 joueurs : la carte appelée (un Roi) et le partenaire qui l'a (null : le preneur joue seul). */
    public ?TarotCard $called = null;
    public ?int $partner = null;
    /** Ce que chaque place a dit aux enchères : un contrat ou « passe ». @var array<int, string> */
    public array $bids = [];
    public bool $chelemAnnounced = false;
    /** @var array<int, Poignee> */
    public array $poignees = [];

    /** @var array<int, list<TarotCard>> */
    public array $hands = [];
    /** @var list<TarotCard> */
    public array $dog = [];
    /** Chien montré à tous (petite, garde), jusqu'à la fin de la donne. @var list<TarotCard> */
    public array $shownDog = [];
    /** Écart du preneur (petite, garde) ou chien mis de côté (garde sans, garde contre). @var list<TarotCard> */
    public array $discard = [];
    /** @var list<TrickCard> */
    public array $trick = [];
    /** @var list<TrickCard> */
    public array $lastTrick = [];
    /** @var list<TrickCard> */
    public array $history = [];

    /** Cartes gagnées par chaque camp pendant la donne. @var array{list<TarotCard>, list<TarotCard>} */
    public array $campCards = [[], []];
    /** @var array{int, int} */
    public array $tricksTaken = [0, 0];
    /** Camp qui doit une demi-carte à l'autre pour garder son Excuse (null : rien à échanger). */
    public ?int $excuseDebt = null;
    public ?int $petitAuBout = null;

    /** @var list<int> */
    public array $scores = [];
    /** @var list<list<int>> */
    public array $dealScores = [];
    public ?int $winner = null;

    public function __construct(
        /** Nombre de donnes de la partie. */
        public readonly int $totalDeals,
        public int $dealer,
        /** 3, 4 ou 5 joueurs. */
        public readonly int $players = 4,
    ) {
        $this->hands = array_fill(0, $players, []);
        $this->scores = array_fill(0, $players, 0);
    }

    public function camp(int $position): int
    {
        return $position === $this->taker || (null !== $this->partner && $position === $this->partner) ? 0 : 1;
    }

    /** Le Roi appelé a été joué : tout le monde sait qui est le partenaire. */
    public function partnerRevealed(): bool
    {
        if (null === $this->called) {
            return true;
        }

        foreach ($this->history as $played) {
            if ($played->card->equals($this->called)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ce qu'un joueur sait : « other » joue-t-il avec lui ? À 5 joueurs, tant que le Roi appelé n'est pas sorti,
     * seul le partenaire sait qu'il est avec le preneur ; les défenseurs ne voient que le preneur comme adversaire.
     */
    public function seenAsAlly(int $viewer, int $other): bool
    {
        if ($viewer === $other) {
            return true;
        }

        if (5 !== $this->players || $this->partnerRevealed()) {
            return $this->camp($viewer) === $this->camp($other);
        }

        $isPartner = null !== $this->called && null !== $this->indexIn($this->hands[$viewer], $this->called);

        return match (true) {
            $viewer === $this->taker => false,
            $isPartner => $other === $this->taker,
            default => $other !== $this->taker,
        };
    }

    /**
     * @param list<TarotCard> $cards
     */
    private function indexIn(array $cards, TarotCard $card): ?int
    {
        foreach ($cards as $index => $held) {
            if ($held->equals($card)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return list<TarotCard>
     */
    public function playableCards(int $position): array
    {
        if (TarotPhase::Playing !== $this->phase || $position !== $this->turn) {
            return [];
        }

        return TarotRules::playableCards($this->hands[$position], $this->trick);
    }

    public function next(int $position): int
    {
        return TarotRules::next($position, $this->players);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $codes = static fn (array $cards): array => array_map(static fn (TarotCard $card): string => $card->code(), $cards);
        $trick = static fn (array $trick): array => array_map(static fn (TrickCard $played): array => [$played->position, $played->card->code()], $trick);

        return [
            'deals' => $this->totalDeals,
            'players' => $this->players,
            'called' => $this->called?->code(),
            'partner' => $this->partner,
            'dealer' => $this->dealer,
            'phase' => $this->phase->value,
            'deal' => $this->dealNumber,
            'turn' => $this->turn,
            'taker' => $this->taker,
            'contract' => $this->contract?->value,
            'bids' => $this->bids,
            'chelem' => $this->chelemAnnounced,
            'poignees' => array_map(static fn (Poignee $poignee): string => $poignee->value, $this->poignees),
            'hands' => array_map($codes, $this->hands),
            'dog' => $codes($this->dog),
            'shownDog' => $codes($this->shownDog),
            'discard' => $codes($this->discard),
            'trick' => $trick($this->trick),
            'lastTrick' => $trick($this->lastTrick),
            'history' => $trick($this->history),
            'campCards' => array_map($codes, $this->campCards),
            'tricksTaken' => $this->tricksTaken,
            'excuseDebt' => $this->excuseDebt,
            'petitAuBout' => $this->petitAuBout,
            'scores' => $this->scores,
            'dealScores' => $this->dealScores,
            'winner' => $this->winner,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $cards = static fn (array $codes): array => array_map(TarotCard::fromCode(...), $codes);
        $trick = static fn (array $rows): array => array_map(static fn (array $row): TrickCard => new TrickCard($row[0], TarotCard::fromCode($row[1])), $rows);

        $state = new self($data['deals'], $data['dealer'], $data['players'] ?? 4);
        $state->called = null === ($data['called'] ?? null) ? null : TarotCard::fromCode($data['called']);
        $state->partner = $data['partner'] ?? null;
        $state->phase = TarotPhase::from($data['phase']);
        $state->dealNumber = $data['deal'];
        $state->turn = $data['turn'];
        $state->taker = $data['taker'];
        $state->contract = null === $data['contract'] ? null : TarotContract::from($data['contract']);
        $state->bids = $data['bids'];
        $state->chelemAnnounced = $data['chelem'];
        $state->poignees = array_map(Poignee::from(...), $data['poignees']);
        $state->hands = array_map($cards, $data['hands']);
        $state->dog = $cards($data['dog']);
        $state->shownDog = $cards($data['shownDog'] ?? []);
        $state->discard = $cards($data['discard']);
        $state->trick = $trick($data['trick']);
        $state->lastTrick = $trick($data['lastTrick']);
        $state->history = $trick($data['history']);
        $state->campCards = array_map($cards, $data['campCards']);
        $state->tricksTaken = $data['tricksTaken'];
        $state->excuseDebt = $data['excuseDebt'];
        $state->petitAuBout = $data['petitAuBout'];
        $state->scores = $data['scores'];
        $state->dealScores = $data['dealScores'];
        $state->winner = $data['winner'];

        return $state;
    }
}
