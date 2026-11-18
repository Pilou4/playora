<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Golf;

use App\Game\Golf\GolfCard;
use App\Game\Golf\GolfEngine;
use App\Game\Golf\GolfException;
use App\Game\Golf\GolfState;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

final class GolfEngineTest extends TestCase
{
    private GolfEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new GolfEngine(new Randomizer(new Xoshiro256StarStar(1)));
    }

    public function testTheDeal(): void
    {
        $two = $this->engine->deal(7, 2, 1);
        $five = $this->engine->deal(7, 5, 4);

        self::assertSame([6, 6], array_map('count', $two->grids));
        self::assertSame(52 - 12 - 1, \count($two->stock));
        self::assertCount(1, $two->discard);
        self::assertSame(GolfState::START, $two->phase);
        self::assertSame(0, $two->turn);
        self::assertSame([0, 0], [$two->upCount(0), $two->upCount(1)]);
        // Deux jeux à partir de 5 joueurs
        self::assertSame(104 - 30 - 1, \count($five->stock));
        self::assertSame($two->grids, $this->engine->deal(7, 2, 1)->grids);
    }

    public function testCardPoints(): void
    {
        self::assertSame(1, GolfCard::fromCode('coeur-as-1')->points());
        self::assertSame(-2, GolfCard::fromCode('pique-2-1')->points());
        self::assertSame(7, GolfCard::fromCode('trefle-7-2')->points());
        self::assertSame(10, GolfCard::fromCode('carreau-valet-1')->points());
        self::assertSame(10, GolfCard::fromCode('carreau-dame-1')->points());
        self::assertSame(0, GolfCard::fromCode('coeur-roi-1')->points());
        self::assertSame('carreau-dame-2', GolfCard::fromCode('carreau-dame-2')->code());

        $this->expectException(GolfException::class);
        GolfCard::fromCode('coeur-1-1');
    }

    public function testAPairInAColumnCountsZero(): void
    {
        // Colonnes : 7♥/7♦ (paire : 0), Roi/2 (0 − 2), Dame/As (10 + 1)
        self::assertSame(9, $this->engine->score(['coeur-7-1', 'trefle-roi-1', 'pique-dame-1', 'carreau-7-1', 'pique-2-1', 'coeur-as-1']));
        // Deux 2 dans une colonne : 0 aussi (pas −4)
        self::assertSame(0, $this->engine->score(['coeur-2-1', 'trefle-roi-1', 'pique-5-1', 'carreau-2-1', 'pique-roi-1', 'coeur-5-1']));
    }

    public function testEveryoneRevealsTwoCardsBeforePlaying(): void
    {
        $state = $this->engine->deal(3, 2, 1);

        $this->expectRefusal(fn () => $this->engine->draw($state, 0), 'Retournez d’abord 2 cartes.');

        $this->engine->reveal($state, 0, 0);
        $this->expectRefusal(fn () => $this->engine->reveal($state, 0, 0), 'Cette carte est déjà retournée.');
        $this->expectRefusal(fn () => $this->engine->reveal($state, 0, 6), 'Cette place n’existe pas.');
        $this->engine->reveal($state, 0, 4);
        $this->expectRefusal(fn () => $this->engine->reveal($state, 0, 1), 'Vous avez déjà retourné vos 2 cartes : attendez les autres joueurs.');
        self::assertSame(GolfState::START, $state->phase);

        // Temps écoulé : le serveur retourne les cartes des retardataires
        $this->engine->autoPlay($state);
        self::assertSame(GolfState::PLAY, $state->phase);
        self::assertSame(2, $state->upCount(1));
    }

    public function testTakingTheDiscardSwapsACard(): void
    {
        $state = $this->playing(top: 'pique-2-1');
        $old = $state->grids[0][5];

        $this->expectRefusal(fn () => $this->engine->takeDiscard($state, 1, 0), 'Ce n’est pas votre tour.');
        $this->engine->takeDiscard($state, 0, 5);

        self::assertSame('pique-2-1', $state->grids[0][5]);
        self::assertTrue($state->up[0][5]);
        self::assertSame($old, $state->top());
        self::assertSame(1, $state->turn);
    }

    public function testADrawnCardIsSwappedOrThrownAway(): void
    {
        $state = $this->playing();
        $this->expectRefusal(fn () => $this->engine->swap($state, 0, 1), 'Piochez d’abord une carte, ou prenez celle de la défausse.');

        $this->engine->draw($state, 0);
        $drawn = $state->drawn;
        self::assertNotNull($drawn);
        $this->expectRefusal(fn () => $this->engine->draw($state, 0), 'Vous avez déjà pioché : échangez la carte ou jetez-la.');
        $this->expectRefusal(fn () => $this->engine->takeDiscard($state, 0, 1), 'Vous avez pioché : échangez la carte piochée ou jetez-la.');

        $this->engine->swap($state, 0, 1);
        self::assertSame($drawn, $state->grids[0][1]);
        self::assertSame(1, $state->turn);

        $this->engine->draw($state, 1);
        $drawn = $state->drawn;
        // Jeter, c'est retourner une carte cachée (pas une carte déjà visible)
        $this->expectRefusal(fn () => $this->engine->discardAndReveal($state, 1, 0), 'Cette carte est déjà retournée.');
        $this->engine->discardAndReveal($state, 1, 2);

        self::assertSame($drawn, $state->top());
        self::assertTrue($state->up[1][2]);
        self::assertNull($state->drawn);
        self::assertSame(0, $state->turn);
    }

    public function testTheOthersPlayOnceMoreAfterAPlayerRevealsEverything(): void
    {
        $state = $this->playing(players: 3);
        $state->up[0] = [true, true, true, true, true, false];

        $this->engine->draw($state, 0);
        $this->engine->discardAndReveal($state, 0, 5);
        self::assertSame(0, $state->closer);
        self::assertSame(GolfState::PLAY, $state->phase);

        $this->engine->draw($state, 1);
        $this->engine->discardAndReveal($state, 1, 1);
        self::assertSame(GolfState::PLAY, $state->phase);

        $this->engine->draw($state, 2);
        $this->engine->discardAndReveal($state, 2, 1);

        // Le tour revient au premier : le trou est fini, toutes les cartes sont retournées et comptées
        self::assertTrue($state->isOver());
        self::assertSame([6, 6, 6], array_map(static fn (int $p): int => $state->upCount($p), [0, 1, 2]));
        self::assertSame(array_map($this->engine->score(...), $state->grids), $state->points);
        $this->expectRefusal(fn () => $this->engine->draw($state, 0), 'Le trou est terminé.');
    }

    public function testAnEmptyStockIsRefilledFromTheDiscard(): void
    {
        $state = $this->playing();
        $state->discard = [...$state->stock, 'coeur-roi-1'];
        $state->stock = [];

        $this->engine->draw($state, 0);

        self::assertSame(['coeur-roi-1'], $state->discard);
        self::assertNotEmpty($state->stock);
    }

    public function testRobotsFinishEveryHole(): void
    {
        foreach (range(2, 6) as $players) {
            $state = $this->engine->deal($players * 11, $players, 0);

            for ($turn = 0; $turn < 500 && !$state->isOver(); ++$turn) {
                $this->engine->autoPlay($state);
            }

            self::assertTrue($state->isOver(), $players.' joueurs');
            self::assertCount($players, $state->points);
            self::assertSame($players >= 5 ? 104 : 52, \count($state->stock) + \count($state->discard) + 6 * $players);
        }
    }

    public function testTheRobotKeepsALowCard(): void
    {
        $state = $this->playing(top: 'pique-2-1');
        $state->grids[0] = ['coeur-dame-1', 'coeur-9-1', 'coeur-3-1', 'trefle-4-1', 'carreau-6-1', 'carreau-5-1'];
        $state->up[0] = [true, false, false, true, false, false];

        $this->engine->autoPlay($state);

        // Le 2 (−2) remplace la Dame (10)
        self::assertSame('pique-2-1', $state->grids[0][0]);
        self::assertSame('coeur-dame-1', $state->top());
    }

    /**
     * Un trou en cours de jeu : chacun a retourné ses cartes 0 et 3, au premier joueur de jouer.
     */
    private function playing(int $players = 2, ?string $top = null): GolfState
    {
        $state = $this->engine->deal(5, $players, $players - 1);

        foreach (range(0, $players - 1) as $position) {
            $state->up[$position] = [true, false, false, true, false, false];
        }

        $state->phase = GolfState::PLAY;

        if (null !== $top) {
            $state->stock = array_values(array_diff($state->stock, [$top]));
            $state->discard = [$top];
        }

        return $state;
    }

    /**
     * @param \Closure(): void $move
     */
    private function expectRefusal(\Closure $move, string $message): void
    {
        try {
            $move();
            self::fail('Coup accepté : '.$message);
        } catch (GolfException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
