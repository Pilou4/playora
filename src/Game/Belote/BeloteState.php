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
    public ?int $beloteHolder = null;
    /** Nombre de cartes de la belote (Roi, Dame d'atout) déjà jouées par celui qui les a. */
    public int $belotePlayed = 0;
    public int $passes = 0;
    /** Points mis de côté par un litige. */
    public int $pending = 0;
    public ?int $winnerTeam = null;

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

    /** Statistiques de chaque place sur toute la partie. @var list<int> */
    public array $playerTricks = [0, 0, 0, 0];
    /** @var list<int> */
    public array $playerPoints = [0, 0, 0, 0];

    public function __construct(
        public readonly int $targetPoints,
        public int $dealer,
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
        if (Phase::Playing !== $this->phase || $position !== $this->turn || null === $this->trump) {
            return [];
        }

        return BeloteRules::playableCards($this->hands[$position], $this->trick, $this->trump, $position);
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
            'dealer' => $this->dealer,
            'phase' => $this->phase->value,
            'deal' => $this->dealNumber,
            'turn' => $this->turn,
            'turned' => $this->turnedCard?->code(),
            'taker' => $this->taker,
            'trump' => $this->trump?->value,
            'beloteHolder' => $this->beloteHolder,
            'belotePlayed' => $this->belotePlayed,
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

        $state = new self($data['target'], $data['dealer']);
        $state->phase = Phase::from($data['phase']);
        $state->dealNumber = $data['deal'];
        $state->turn = $data['turn'];
        $state->turnedCard = null === $data['turned'] ? null : Card::fromCode($data['turned']);
        $state->taker = $data['taker'];
        $state->trump = null === $data['trump'] ? null : Suit::from($data['trump']);
        $state->beloteHolder = $data['beloteHolder'];
        $state->belotePlayed = $data['belotePlayed'];
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
        $state->playerTricks = $data['playerTricks'];
        $state->playerPoints = $data['playerPoints'];

        return $state;
    }
}
