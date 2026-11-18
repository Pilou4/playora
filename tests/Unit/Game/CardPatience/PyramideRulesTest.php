<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\CardPatience;

use App\Game\CardPatience\CardPatienceException;
use App\Game\Pyramide\PyramideRules;
use App\Game\Pyramide\PyramideState;
use PHPUnit\Framework\TestCase;

final class PyramideRulesTest extends TestCase
{
    public function testTheDealBuildsAPyramidOf28Cards(): void
    {
        $state = (new PyramideRules())->deal(3);

        self::assertCount(28, $state->pyramid);
        self::assertCount(24, $state->stock);
        // Seule la dernière rangée est libre au départ
        self::assertSame(range(21, 27), array_values(array_filter(range(0, 27), $state->isFree(...))));
    }

    public function testTwoFreeCardsMaking13LeaveTogether(): void
    {
        $rules = new PyramideRules();
        $state = $this->pyramid();
        $state->pyramid[21] = 'coeur-6';
        $state->pyramid[22] = 'pique-7';
        $state->pyramid[23] = 'trefle-roi';
        $state->pyramid[24] = 'pique-8';

        self::assertContains('pique-7', $rules->targets($state)['coeur-6']);
        $this->refused(fn () => $rules->move($state, 'coeur-6', 'pique-8'), 'Les deux cartes doivent faire 13 (Valet 11, Dame 12, Roi 13 tout seul).');
        $this->refused(fn () => $rules->move($state, 'pique-8', 'seul'), 'Seul un Roi part tout seul.');

        $rules->move($state, 'coeur-6', 'pique-7');
        self::assertNull($state->pyramid[21]);
        self::assertNull($state->pyramid[22]);
        self::assertSame(2000, $state->score);
        // La carte du dessus (rangée 6, place 0) est maintenant libre
        self::assertTrue($state->isFree(15));

        $rules->move($state, 'trefle-roi', 'seul');
        self::assertSame(3000, $state->score);
        $this->refused(fn () => $rules->move($state, $state->pyramid[0], 'seul'), 'Cette carte est encore couverte.');
    }

    public function testTheWasteCardPairsAndTheStockPassesTwice(): void
    {
        $rules = new PyramideRules();
        $state = $this->pyramid();
        $state->pyramid[27] = 'carreau-dame';
        $state->stock = ['pique-as'];

        $rules->draw($state);
        self::assertSame(['pique-as'], $state->waste);
        $rules->move($state, 'pique-as', 'carreau-dame');
        self::assertSame([], $state->waste);
        self::assertSame(1200, $state->score);

        $state->waste = ['pique-3'];
        $rules->draw($state);
        $rules->draw($state);
        $rules->draw($state);
        $rules->draw($state);
        self::assertSame(2, $state->passes);
        $this->refused(fn () => $rules->draw($state), 'Le talon est épuisé.');
    }

    public function testAnEmptyPyramidIsComplete(): void
    {
        $state = $this->pyramid();
        $state->pyramid = array_fill(0, 28, null);

        self::assertTrue($state->isComplete());
        self::assertSame(28, $state->sortedCards());
    }

    private function pyramid(): PyramideState
    {
        $state = new PyramideState(1);
        $state->pyramid = array_map(static fn (int $index): string => ['pique', 'coeur', 'carreau', 'trefle'][$index % 4].'-'.(2 + intdiv($index, 4) % 3), range(0, 27));

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
