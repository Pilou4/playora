<?php

declare(strict_types=1);

namespace App\Game\Belote;

/**
 * Tout l'état d'une partie, enregistré en JSON. Seul le serveur le voit en entier :
 * chaque joueur ne reçoit que sa propre main (voir BeloteView).
 */
final class BeloteState
{
    public Phase $phase = Phase::FirstBidding;
    public int $dealNumber = 0;
    public int $turn = 0;
    public ?Card $turnedCard = null;
    public ?int $taker = null;
    public ?Suit $trump = null;
    /** Coinche : contrat à une couleur, en tout atout ou en sans atout (alors $trump est null). */
    public TrumpMode $mode = TrumpMode::Suit;
    public ?int $beloteHolder = null;
    /** Nombre de cartes de la belote (Roi, Dame d'atout) déjà jouées par celui qui les a. */
    public int $belotePlayed = 0;
    /**
     * Tout atout (coinche) : chaque Roi et Dame de la même couleur dans une main est une belote (20 points). Sans atout : aucune.
     *
     * @var list<array{position: int, suit: string, played: int}>
     */
    public array $modeBelotes = [];
    public int $passes = 0;
    /** Points mis de côté par un litige. */
    public int $pending = 0;
    public ?int $winnerTeam = null;

    /** Coinche et contrée : contrat en cours (80 à 160, ou 250 pour un capot), coinche (×2) ou surcoinche (×4). */
    public ?int $contract = null;
    public int $multiplier = 1;
    /** Celui qui a coinché (null si personne). */
    public ?int $coincher = null;
    /** Dernière annonce de chaque place pendant les enchères en cours. @var array<int, int> */
    public array $bids = [];

    /** Annonces déclarées pendant la donne, par place. @var array<int, list<Announcement>> */
    public array $declarations = [];
    /** Équipe qui marque ses annonces (connue à la fin du premier pli), et leurs points. */
    public ?int $announceTeam = null;
    public int $announcePoints = 0;

    /** @var array<int, list<Card>> */
    public array $hands = [[], [], [], []];
    /** @var list<Card> */
    public array $stock = [];
    /** @var list<TrickCard> */
    public array $trick = [];
    /** @var list<TrickCard> */
    public array $lastTrick = [];
    /** Cartes jouées depuis le début de la donne, dans l'ordre (ce que tout le monde a vu). @var list<TrickCard> */
    public array $history = [];

    /** @var array{int, int} */
    public array $scores = [0, 0];
    /** Points marqués par chaque équipe à chaque donne, pour le tableau des scores. @var list<array{int, int}> */
    public array $dealScores = [];
    /** @var array{int, int} */
    public array $cardPoints = [0, 0];
    /** @var array{int, int} */
    public array $tricksWon = [0, 0];
    /** Plis gagnés par chaque place pendant la donne en cours (pour la générale). @var list<int> */
    public array $dealTricks = [0, 0, 0, 0];

    /** Statistiques de chaque place sur toute la partie. @var list<int> */
    public array $playerTricks = [0, 0, 0, 0];
    /** @var list<int> */
    public array $playerPoints = [0, 0, 0, 0];

    public function __construct(
        public readonly int $targetPoints,
        public int $dealer,
        public readonly Variant $variant = Variant::Classic,
        /** Partie avec annonces (choisi à la création du salon). */
        public readonly bool $announcements = false,
    ) {
    }

    /**
     * @return list<Card>
     */
    public function hand(int $position): array
    {
        return $this->hands[$position];
    }

    /**
     * @return list<Card>
     */
    public function playableCards(int $position): array
    {
        if (Phase::Playing !== $this->phase || $position !== $this->turn) {
            return [];
        }

        if (TrumpMode::Suit !== $this->mode) {
            return ModeRules::playableCards($this->hands[$position], $this->trick, $this->mode);
        }

        return null === $this->trump ? [] : BeloteRules::playableCards($this->hands[$position], $this->trick, $this->trump, $position);
    }

    /**
     * Place qui remporte le pli, à la couleur d'atout, en tout atout ou en sans atout.
     *
     * @param non-empty-list<TrickCard> $trick
     */
    public function trickWinner(array $trick): int
    {
        return TrumpMode::Suit === $this->mode
            ? BeloteRules::winner($trick, $this->trump ?? throw new BeloteException('Aucun atout.'))
            : ModeRules::winner($trick, $this->mode);
    }

    /**
     * @param list<TrickCard> $trick
     */
    public function trickPoints(array $trick): int
    {
        return TrumpMode::Suit === $this->mode
            ? BeloteRules::points($trick, $this->trump ?? throw new BeloteException('Aucun atout.'))
            : ModeRules::trickPoints($trick, $this->mode);
    }

    /**
     * Points de belote de chaque équipe : 20 pour la belote à l'atout, ou 20 par belote en tout atout (aucune en sans atout).
     *
     * @return array{int, int}
     */
    public function belotePoints(): array
    {
        $points = [0, 0];

        if (null !== $this->beloteHolder) {
            $points[BeloteRules::team($this->beloteHolder)] += BeloteScorer::BELOTE;
        }

        foreach ($this->modeBelotes as $belote) {
            $points[BeloteRules::team($belote['position'])] += BeloteScorer::BELOTE;
        }

        return $points;
    }

    /** L'atout est connu (ou le contrat est en tout atout / sans atout) : on peut jouer. */
    public function hasContract(): bool
    {
        return null !== $this->trump || TrumpMode::Suit !== $this->mode;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $codes = static fn (array $cards): array => array_map(static fn (Card $card): string => $card->code(), $cards);
        $trick = static fn (array $trick): array => array_map(static fn (TrickCard $played): array => [$played->position, $played->card->code()], $trick);

        return [
            'target' => $this->targetPoints,
            'variant' => $this->variant->value,
            'announcements' => $this->announcements,
            'contract' => $this->contract,
            'multiplier' => $this->multiplier,
            'coincher' => $this->coincher,
            'bids' => $this->bids,
            'dealer' => $this->dealer,
            'phase' => $this->phase->value,
            'deal' => $this->dealNumber,
            'turn' => $this->turn,
            'turned' => $this->turnedCard?->code(),
            'taker' => $this->taker,
            'trump' => $this->trump?->value,
            'mode' => $this->mode->value,
            'beloteHolder' => $this->beloteHolder,
            'belotePlayed' => $this->belotePlayed,
            'modeBelotes' => $this->modeBelotes,
            'passes' => $this->passes,
            'pending' => $this->pending,
            'winner' => $this->winnerTeam,
            'hands' => array_map($codes, $this->hands),
            'stock' => $codes($this->stock),
            'trick' => $trick($this->trick),
            'lastTrick' => $trick($this->lastTrick),
            'history' => $trick($this->history),
            'scores' => $this->scores,
            'dealScores' => $this->dealScores,
            'cardPoints' => $this->cardPoints,
            'tricksWon' => $this->tricksWon,
            'dealTricks' => $this->dealTricks,
            'declarations' => array_map(static fn (array $list): array => array_map(static fn (Announcement $a): array => $a->toState(), $list), $this->declarations),
            'announceTeam' => $this->announceTeam,
            'announcePoints' => $this->announcePoints,
            'playerTricks' => $this->playerTricks,
            'playerPoints' => $this->playerPoints,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $cards = static fn (array $codes): array => array_map(Card::fromCode(...), $codes);
        $trick = static fn (array $rows): array => array_map(static fn (array $row): TrickCard => new TrickCard($row[0], Card::fromCode($row[1])), $rows);

        $state = new self($data['target'], $data['dealer'], Variant::from($data['variant'] ?? Variant::Classic->value), $data['announcements'] ?? false);
        $state->contract = $data['contract'] ?? null;
        $state->multiplier = $data['multiplier'] ?? 1;
        $state->coincher = $data['coincher'] ?? null;
        $state->bids = $data['bids'] ?? [];
        $state->phase = Phase::from($data['phase']);
        $state->dealNumber = $data['deal'];
        $state->turn = $data['turn'];
        $state->turnedCard = null === $data['turned'] ? null : Card::fromCode($data['turned']);
        $state->taker = $data['taker'];
        $state->trump = null === $data['trump'] ? null : Suit::from($data['trump']);
        $state->mode = TrumpMode::from($data['mode'] ?? TrumpMode::Suit->value);
        $state->beloteHolder = $data['beloteHolder'];
        $state->belotePlayed = $data['belotePlayed'];
        $state->modeBelotes = $data['modeBelotes'] ?? [];
        $state->passes = $data['passes'];
        $state->pending = $data['pending'];
        $state->winnerTeam = $data['winner'];
        $state->hands = array_map($cards, $data['hands']);
        $state->stock = $cards($data['stock']);
        $state->trick = $trick($data['trick']);
        $state->lastTrick = $trick($data['lastTrick']);
        $state->history = $trick($data['history'] ?? []);
        $state->scores = $data['scores'];
        $state->dealScores = $data['dealScores'] ?? [];
        $state->cardPoints = $data['cardPoints'];
        $state->tricksWon = $data['tricksWon'];
        $state->dealTricks = $data['dealTricks'] ?? [0, 0, 0, 0];
        $state->declarations = array_map(static fn (array $list): array => array_map(Announcement::fromArray(...), $list), $data['declarations'] ?? []);
        $state->announceTeam = $data['announceTeam'] ?? null;
        $state->announcePoints = $data['announcePoints'] ?? 0;
        $state->playerTricks = $data['playerTricks'];
        $state->playerPoints = $data['playerPoints'];

        return $state;
    }
}
