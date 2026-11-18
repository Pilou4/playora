<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\CardPatience;

use App\Game\CardPatience\CardPatienceException;
use App\Game\GolfSolitaire\GolfSolitaireRules;
use App\Game\GolfSolitaire\GolfSolitaireState;
use PHPUnit\Framework\TestCase;

final class GolfSolitaireRulesTest extends TestCase
{
    public function testTheDeal(): void
    {
        $state = (new GolfSolitaireRules())->deal(9);

        self::assertSame([5, 5, 5, 5, 5, 5, 5], array_map('count', $state->columns));
        self::assertCount(1, $state->pile);
        self::assertCount(16, $state->stock);
    }

    public function testATopCardOneUpOrDownGoesOnThePile(): void
    {
        $rules = new GolfSolitaireRules();
        $state = new GolfSolitaireState(1);
        $state->columns = [['pique-2', 'coeur-8'], ['trefle-6'], ['carreau-9'], ['pique-roi'], [], [], []];
        $state->pile = ['coeur-7'];
        $state->stock = ['trefle-as'];

        self::assertSame(['coeur-8' => ['pile'], 'trefle-6' => ['pile']], $rules->targets($state));
        $this->refused(fn () => $rules->move($state, 'carreau-9', 'pile'), 'Il faut une hauteur de plus ou de moins que la carte de la pile.');
        $this->refused(fn () => $rules->move($state, 'pique-2', 'pile'), 'Seule la carte du dessus d’une colonne se joue.');

        $rules->move($state, 'coeur-8', 'pile');
        $rules->move($state, 'carreau-9', 'pile');
        self::assertSame(['coeur-7', 'coeur-8', 'carreau-9'], $state->pile);
        self::assertSame(2000, $state->score);

        $rules->draw($state);
        self::assertSame('trefle-as', $state->pile[2 + 1]);
        $this->refused(fn () => $rules->draw($state), 'Le talon est épuisé.');
    }

    public function testEmptyColumnsAreComplete(): void
    {
        $state = new GolfSolitaireState(1);
        $state->pile = ['coeur-7'];

        self::assertTrue($state->isComplete());
        self::assertSame(35, $state->sortedCards());
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
