<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\CardPatience;

use App\Game\CardPatience\CardPatienceException;
use App\Game\Freecell\FreecellRules;
use App\Game\Freecell\FreecellState;
use PHPUnit\Framework\TestCase;

final class FreecellRulesTest extends TestCase
{
    public function testTheDealPutsAllCardsFaceUpOnEightColumns(): void
    {
        $rules = new FreecellRules();
        $state = $rules->deal(5);

        self::assertSame([7, 7, 7, 7, 6, 6, 6, 6], array_map('count', $state->columns));
        self::assertSame(52, \count(array_unique(array_merge(...$state->columns))));
        self::assertSame($state->columns, $rules->deal(5)->columns, 'Même graine, même donne');
        self::assertSame([null, null, null, null], $state->cells);
    }

    public function testCardsGoToCellsColumnsAndFoundations(): void
    {
        $rules = new FreecellRules();
        $state = $this->board([['pique-roi', 'coeur-dame'], ['coeur-as'], ['trefle-valet'], [], [], [], [], []]);

        $rules->move($state, 'coeur-as', 'famille');
        self::assertSame(1, $state->foundations['coeur']);
        self::assertSame(1000, $state->score);

        $rules->move($state, 'trefle-valet', 'colonne-0');
        self::assertSame(['pique-roi', 'coeur-dame', 'trefle-valet'], $state->columns[0]);

        $rules->move($state, 'trefle-valet', 'cellule-2');
        self::assertSame('trefle-valet', $state->cells[2]);
        $this->refused(fn () => $rules->move($state, 'coeur-dame', 'cellule-2'), 'Cette cellule est occupée.');
        $this->refused(fn () => $rules->move($state, 'trefle-valet', 'famille'), 'Cette carte ne va pas encore sur sa famille.');

        // Retour : la carte revient dans sa colonne, contre 1 000 points
        $rules->undo($state);
        self::assertSame(['pique-roi', 'coeur-dame', 'trefle-valet'], $state->columns[0]);
        self::assertSame(0, $state->score);
    }

    public function testASequenceMovesTogetherWhenCellsAllowIt(): void
    {
        $rules = new FreecellRules();
        $state = $this->board([['coeur-dame', 'pique-valet', 'coeur-10'], ['trefle-roi'], ['pique-2'], ['pique-3'], ['pique-4'], ['pique-5'], ['pique-6'], ['pique-7']]);

        // 3 cartes, 4 cellules libres : (4 + 1) × 1 = 5
        self::assertContains('colonne-1', $rules->targets($state)['coeur-dame']);
        $rules->move($state, 'coeur-dame', 'colonne-1');
        self::assertSame(['trefle-roi', 'coeur-dame', 'pique-valet', 'coeur-10'], $state->columns[1]);

        // Plus de cellule libre : une seule carte à la fois
        $state->cells = ['carreau-2', 'carreau-3', 'carreau-4', 'carreau-5'];
        $this->refused(fn () => $rules->move($state, 'coeur-dame', 'colonne-0'), 'Pas assez de cellules libres pour déplacer toutes ces cartes.');
    }

    public function testUselessCardsGoUpAloneAndAFullBoardIsComplete(): void
    {
        $rules = new FreecellRules();
        $state = $this->board([['coeur-roi'], [], [], [], [], [], [], []]);
        $state->foundations = ['pique' => 13, 'coeur' => 11, 'carreau' => 13, 'trefle' => 13];
        $state->cells = ['coeur-dame', null, null, null];

        $rules->afterMove($state, true);

        self::assertTrue($state->isComplete());
        self::assertSame(2 * 1000 + 10000, $state->score);
    }

    /**
     * @param list<list<string>> $columns
     */
    private function board(array $columns): FreecellState
    {
        $state = new FreecellState(1);
        $state->columns = $columns;

        return $state;
    }

    private function refused(\Closure $move, string $message): void
    {
        try {
            $move();
            self::fail('Coup accepté : '.$message);
        } catch (CardPatienceException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
