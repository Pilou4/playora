<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Huit;

use App\Game\Huit\HuitCard;
use App\Game\Huit\HuitEngine;
use App\Game\Huit\HuitException;
use App\Game\Huit\HuitState;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

final class HuitEngineTest extends TestCase
{
    private HuitEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new HuitEngine(new Randomizer(new Xoshiro256StarStar(1)));
    }

    public function testTheDeal(): void
    {
        $two = $this->engine->deal(7, 2, 1);
        $five = $this->engine->deal(7, 5, 4);

        self::assertSame([8, 8], array_map('count', $two->hands));
        self::assertSame(52 - 16 - 1, \count($two->stock));
        self::assertSame(0, $two->turn);
        self::assertSame(HuitCard::fromCode($two->top() ?? '')->suit, $two->suit);
        // Deux jeux à partir de 5 joueurs
        self::assertSame([7, 7, 7, 7, 7], array_map('count', $five->hands));
        self::assertSame(104 - 35 - 1, \count($five->stock));
        self::assertSame($two->hands, $this->engine->deal(7, 2, 1)->hands);
    }

    public function testSameSuitOrSameRankOrAnEight(): void
    {
        $state = $this->state([['coeur-3-1', 'pique-9-1', 'trefle-5-1', 'carreau-8-1'], ['coeur-roi-1', 'pique-2-1']], top: 'coeur-9-1');

        $this->expectRefusal(fn () => $this->engine->play($state, 0, 'trefle-5-1'), 'Il faut une carte de la couleur demandée ou de la même hauteur (ou un 8).');
        $this->expectRefusal(fn () => $this->engine->play($state, 1, 'coeur-roi-1'), 'Ce n’est pas votre tour.');

        $this->engine->play($state, 0, 'pique-9-1');
        self::assertSame('pique', $state->suit);
        self::assertSame(1, $state->turn);

        $this->engine->play($state, 1, 'pique-2-1', announce: true);
        $this->engine->play($state, 0, 'carreau-8-1', 'trefle');
        self::assertSame('trefle', $state->suit);
    }

    public function testSpecialCards(): void
    {
        // 7 : le sens change ; Valet : le suivant passe ; 10 : on rejoue
        $state = $this->state([['coeur-7-1', 'coeur-valet-1', 'coeur-10-1', 'coeur-2-1', 'coeur-3-1'], ['pique-2-1'], ['pique-3-1'], ['pique-4-1']], top: 'coeur-9-1');

        $this->engine->play($state, 0, 'coeur-7-1');
        self::assertSame(-1, $state->direction);
        self::assertSame(3, $state->turn);

        $state->turn = 0;
        $this->engine->play($state, 0, 'coeur-valet-1');
        self::assertSame(2, $state->turn);

        $state->turn = 0;
        $this->engine->play($state, 0, 'coeur-10-1');
        self::assertSame(0, $state->turn);
    }

    public function testAcesMakeTheNextPlayerDrawUnlessHeAddsAnAce(): void
    {
        $state = $this->state([['coeur-as-1', 'pique-3-1', 'trefle-4-1'], ['pique-as-1', 'pique-5-1', 'trefle-6-1'], ['carreau-2-1', 'carreau-4-1']], top: 'coeur-9-1', stock: ['trefle-2-1', 'trefle-3-1', 'trefle-7-1', 'trefle-9-1', 'trefle-10-1', 'trefle-dame-1']);

        $this->engine->play($state, 0, 'coeur-as-1');
        self::assertSame(2, $state->penalty);

        $this->expectRefusal(fn () => $this->engine->play($state, 1, 'pique-5-1'), 'Posez un As, ou piochez les 2 cartes.');
        $this->engine->play($state, 1, 'pique-as-1');
        self::assertSame(4, $state->penalty);

        // Le 3e joueur pioche 4 cartes et le tour passe
        $this->engine->draw($state, 2);
        self::assertCount(6, $state->hands[2]);
        self::assertSame(0, $state->penalty);
        self::assertSame(0, $state->turn);
    }

    public function testForgettingToSayCardCostsTwoCards(): void
    {
        $state = $this->state([['coeur-3-1', 'coeur-4-1'], ['pique-2-1']], top: 'coeur-9-1', stock: ['trefle-2-1', 'trefle-3-1']);

        $this->engine->play($state, 0, 'coeur-3-1');

        self::assertCount(3, $state->hands[0]);
    }

    public function testADrawnCardCanBePlayedAtOnceOrTheTurnPasses(): void
    {
        $state = $this->state([['pique-3-1', 'trefle-4-1'], ['pique-2-1']], top: 'coeur-9-1', stock: ['coeur-5-1']);

        $this->engine->draw($state, 0);
        self::assertSame('coeur-5-1', $state->drawn);
        self::assertSame(0, $state->turn);
        $this->expectRefusal(fn () => $this->engine->play($state, 0, 'pique-3-1'), 'Après avoir pioché, seule la carte piochée peut être posée.');
        $this->expectRefusal(fn () => $this->engine->draw($state, 0), 'Vous avez déjà pioché : posez la carte piochée ou passez.');

        $this->engine->pass($state, 0);
        self::assertSame(1, $state->turn);
        self::assertNull($state->drawn);

        // Une carte piochée qui ne va pas : le tour passe tout seul
        $state = $this->state([['pique-3-1'], ['pique-2-1']], top: 'coeur-9-1', stock: ['trefle-5-1']);
        $this->engine->draw($state, 0);
        self::assertSame(1, $state->turn);
    }

    public function testTheRoundEndsWithPenalties(): void
    {
        $state = $this->state([['coeur-3-1'], ['pique-8-1', 'trefle-as-1', 'carreau-roi-1', 'carreau-4-1']], top: 'coeur-9-1');

        $this->engine->play($state, 0, 'coeur-3-1');

        self::assertTrue($state->isOver());
        self::assertSame(0, $state->winner);
        self::assertSame([0, 75], $state->penalties);
        $this->expectRefusal(fn () => $this->engine->draw($state, 1), 'La manche est terminée.');
    }

    public function testRoundsPlayedAutomaticallyEndAndNoCardIsLost(): void
    {
        foreach (range(1, 40) as $seed) {
            $players = 2 + $seed % 5;
            $state = $this->engine->deal($seed, $players, 0);

            for ($turn = 0; $turn < 3000 && !$state->isOver(); ++$turn) {
                $this->engine->autoPlay($state);
            }

            self::assertTrue($state->isOver(), 'Manche sans fin, graine '.$seed);
            self::assertSame($players >= 5 ? 104 : 52, \count($state->stock) + \count($state->pile) + array_sum(array_map('count', $state->hands)));
            self::assertSame($state->toArray(), HuitState::fromArray($state->toArray())->toArray());
        }
    }

    /**
     * @param list<list<string>> $hands
     * @param list<string>       $stock
     */
    private function state(array $hands, string $top, array $stock = []): HuitState
    {
        $state = new HuitState(\count($hands), \count($hands) - 1);
        $state->hands = $hands;
        $state->pile = [$top];
        $state->suit = HuitCard::fromCode($top)->suit;
        $state->stock = $stock;

        return $state;
    }

    private function expectRefusal(\Closure $action, string $message): void
    {
        try {
            $action();
            self::fail('Coup accepté : '.$message);
        } catch (HuitException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
