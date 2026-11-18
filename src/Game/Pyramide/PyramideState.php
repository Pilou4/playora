<?php

declare(strict_types=1);

namespace App\Game\Pyramide;

use App\Game\CardPatience\CardPatienceState;

/**
 * Une grille de Pyramide : 28 cartes en pyramide de 7 rangées (rangée r, place c : index r × (r + 1) / 2 + c),
 * le talon (24 cartes, face cachée, la dernière est celle du dessus) et la défausse (la dernière se joue).
 *
 * - pyramid : les 28 places (null : carte déjà enlevée) ;
 * - passes : nombre de fois où la défausse est repassée dans le talon (2 au plus).
 */
final class PyramideState extends CardPatienceState
{
    public const int ROWS = 7;
    public const int MAX_PASSES = 2;

    /** @var list<?string> */
    public array $pyramid = [];
    /** @var list<string> */
    public array $stock = [];
    /** @var list<string> */
    public array $waste = [];
    public int $passes = 0;

    public function sortedCards(): int
    {
        return \count(array_filter($this->pyramid, static fn (?string $card): bool => null === $card));
    }

    public function cardCount(): int
    {
        return 28;
    }

    public function board(): array
    {
        return ['pyramid' => $this->pyramid, 'stock' => $this->stock, 'waste' => $this->waste, 'passes' => $this->passes];
    }

    public function restore(array $board): void
    {
        $this->pyramid = $board['pyramid'];
        $this->stock = $board['stock'];
        $this->waste = $board['waste'];
        $this->passes = (int) $board['passes'];
    }

    /** Une carte de la pyramide est libre quand plus rien ne la couvre (les deux cartes de la rangée du dessous sont parties). */
    public function isFree(int $index): bool
    {
        if (null === ($this->pyramid[$index] ?? null)) {
            return false;
        }

        $row = (int) floor((sqrt(8 * $index + 1) - 1) / 2);
        $column = $index - intdiv($row * ($row + 1), 2);

        if (self::ROWS - 1 === $row) {
            return true;
        }

        $below = intdiv(($row + 1) * ($row + 2), 2) + $column;

        return null === $this->pyramid[$below] && null === $this->pyramid[$below + 1];
    }
}
