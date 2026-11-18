<?php

declare(strict_types=1);

namespace App\Game\President;

/**
 * Une manche de Président en cours, enregistrée en JSON. Les places vont de 0 à players - 1.
 *
 * - hands : la main de chaque place ;
 * - phase : « echange » (le Président et le Vice-président rendent des cartes), « jeu », puis « finie » ;
 * - trick : les poses du pli en cours, dans l'ordre ([{p: place, cards: [...]}]) ; la dernière est à battre ;
 * - passed : les places qui ont passé depuis la dernière pose ;
 * - cleared : le dernier pli écarté (montré au joueur tant que le pli suivant n'a pas commencé) ;
 * - order : les places dans l'ordre où elles ont fini (la première est Président) ;
 * - roles : les titres gagnés à la manche précédente (null à la première manche) ;
 * - toGive : cartes que chaque place doit encore rendre pendant l'échange (0 : rien) ; giveTo : à qui ;
 * - received / given : les cartes reçues et données pendant l'échange de cette manche (affichées au joueur) ;
 * - points : points de la manche, une fois finie (1 par joueur battu).
 */
final class PresidentState
{
    public const string EXCHANGE = 'echange';
    public const string PLAY = 'jeu';
    public const string OVER = 'finie';

    public const string PRESIDENT = 'president';
    public const string VICE_PRESIDENT = 'vice-president';
    public const string NEUTRAL = 'neutre';
    public const string VICE_POOR = 'vice-pauvre';
    public const string POOR = 'pauvre';

    /** @var list<list<string>> */
    public array $hands = [];
    public string $phase = self::PLAY;
    public int $turn = 0;
    /** @var list<array{p: int, cards: list<string>}> */
    public array $trick = [];
    /** @var list<int> */
    public array $passed = [];
    /** @var list<array{p: int, cards: list<string>}> */
    public array $cleared = [];
    /** @var list<int> */
    public array $order = [];
    /** @var list<string>|null */
    public ?array $roles = null;
    /** @var list<int> */
    public array $toGive = [];
    /** @var list<?int> */
    public array $giveTo = [];
    /** @var list<list<string>> */
    public array $received = [];
    /** @var list<list<string>> */
    public array $given = [];
    /** @var list<int> */
    public array $points = [];

    public function __construct(
        public readonly int $players,
    ) {
        $this->hands = array_fill(0, $players, []);
        $this->toGive = array_fill(0, $players, 0);
        $this->giveTo = array_fill(0, $players, null);
        $this->received = array_fill(0, $players, []);
        $this->given = array_fill(0, $players, []);
    }

    public function isOver(): bool
    {
        return self::OVER === $this->phase;
    }

    /** La dernière pose du pli, à battre (null : le pli est vide, le joueur a la main). */
    public function lastPlay(): ?array
    {
        return [] === $this->trick ? null : $this->trick[\count($this->trick) - 1];
    }

    /** Places qui ont encore des cartes. @return list<int> */
    public function active(): array
    {
        return array_values(array_filter(range(0, $this->players - 1), fn (int $position): bool => [] !== $this->hands[$position]));
    }

    /**
     * Titres d'une manche selon l'ordre de fin : Président, Vice-président (dès 4 joueurs), Neutres,
     * Vice-pauvre (dès 4 joueurs), Pauvre.
     *
     * @param list<int> $order
     *
     * @return list<string>
     */
    public static function rolesFor(array $order): array
    {
        $count = \count($order);
        $roles = array_fill(0, $count, self::NEUTRAL);

        foreach ($order as $rank => $position) {
            $roles[$position] = match (true) {
                0 === $rank => self::PRESIDENT,
                $count - 1 === $rank => self::POOR,
                $count >= 4 && 1 === $rank => self::VICE_PRESIDENT,
                $count >= 4 && $count - 2 === $rank => self::VICE_POOR,
                default => self::NEUTRAL,
            };
        }

        return $roles;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'players' => $this->players,
            'hands' => $this->hands,
            'phase' => $this->phase,
            'turn' => $this->turn,
            'trick' => $this->trick,
            'passed' => $this->passed,
            'cleared' => $this->cleared,
            'order' => $this->order,
            'roles' => $this->roles,
            'toGive' => $this->toGive,
            'giveTo' => $this->giveTo,
            'received' => $this->received,
            'given' => $this->given,
            'points' => $this->points,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $state = new self($data['players']);

        foreach (['hands', 'phase', 'turn', 'trick', 'passed', 'cleared', 'order', 'roles', 'toGive', 'giveTo', 'received', 'given', 'points'] as $field) {
            $state->{$field} = $data[$field];
        }

        return $state;
    }
}
