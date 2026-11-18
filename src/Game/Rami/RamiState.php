<?php

declare(strict_types=1);

namespace App\Game\Rami;

/**
 * Une manche de Rami en cours, enregistrée en JSON. Les cartes sont des codes (« coeur-7-1 », « joker-3 »).
 *
 * - hands : la main de chaque place (à partir de 0) ;
 * - stock : la pioche (la carte du dessus est la dernière) ; discard : la défausse (idem) ;
 * - melds : les combinaisons posées, chacune avec sa place (owner) ;
 * - opened : les places qui ont fait leur première pose ;
 * - phase : « pioche » (le joueur doit prendre une carte) ou « jeu » (il pose, complète, puis défausse) ;
 * - taken : la carte prise dans la défausse ce tour-ci, qu'il faut poser avant de défausser.
 */
final class RamiState
{
    public const string DRAW = 'pioche';
    public const string PLAY = 'jeu';
    public const string OVER = 'finie';

    /** @var list<list<string>> */
    public array $hands = [];
    /** @var list<string> */
    public array $stock = [];
    /** @var list<string> */
    public array $discard = [];
    /** @var list<array{owner: int, meld: RamiMeld}> */
    public array $melds = [];
    /** @var list<bool> */
    public array $opened = [];
    public int $turn = 0;
    public string $phase = self::DRAW;
    public ?string $taken = null;
    /** La place qui a fini la manche, et si elle a tout posé d'un coup (« rami sec ») */
    public ?int $winner = null;
    public bool $ramiSec = false;
    /** Le joueur dont c'est le tour avait déjà posé au début de son tour (sinon, finir d'un coup est un « rami sec ») */
    public bool $openedBefore = false;
    /** @var list<int> pénalités de la manche, une fois finie */
    public array $penalties = [];

    public function __construct(
        public readonly int $players,
        public readonly int $dealer,
    ) {
        $this->hands = array_fill(0, $players, []);
        $this->opened = array_fill(0, $players, false);
    }

    public function isOver(): bool
    {
        return self::OVER === $this->phase;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'players' => $this->players,
            'dealer' => $this->dealer,
            'hands' => $this->hands,
            'stock' => $this->stock,
            'discard' => $this->discard,
            'melds' => array_map(static fn (array $entry): array => ['owner' => $entry['owner'], ...$entry['meld']->toArray()], $this->melds),
            'opened' => $this->opened,
            'turn' => $this->turn,
            'phase' => $this->phase,
            'taken' => $this->taken,
            'winner' => $this->winner,
            'ramiSec' => $this->ramiSec,
            'openedBefore' => $this->openedBefore,
            'penalties' => $this->penalties,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $state = new self($data['players'], $data['dealer']);
        $state->hands = $data['hands'];
        $state->stock = $data['stock'];
        $state->discard = $data['discard'];
        $state->melds = array_map(static fn (array $entry): array => ['owner' => $entry['owner'], 'meld' => RamiMeld::fromArray($entry)], $data['melds']);
        $state->opened = $data['opened'];
        $state->turn = $data['turn'];
        $state->phase = $data['phase'];
        $state->taken = $data['taken'];
        $state->winner = $data['winner'];
        $state->ramiSec = $data['ramiSec'];
        $state->openedBefore = $data['openedBefore'];
        $state->penalties = $data['penalties'];

        return $state;
    }
}
