<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\President;

use App\Game\President\PresidentCard;
use App\Game\President\PresidentEngine;
use App\Game\President\PresidentException;
use App\Game\President\PresidentState;
use PHPUnit\Framework\TestCase;

final class PresidentEngineTest extends TestCase
{
    private PresidentEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new PresidentEngine();
    }

    public function testTheCardsOrderGoesFromTheThreeToTheTwo(): void
    {
        self::assertSame(0, PresidentCard::powerOf('pique-3'));
        self::assertSame(11, PresidentCard::powerOf('coeur-as'));
        self::assertSame(12, PresidentCard::powerOf('trefle-2'));
        self::assertSame(['carreau-3', 'pique-10', 'coeur-roi', 'pique-2'], PresidentCard::sort(['pique-2', 'coeur-roi', 'carreau-3', 'pique-10']));
        self::assertCount(52, PresidentCard::deck());
    }

    public function testTheFirstDealGivesAllTheCardsAndTheQueenOfHeartsStarts(): void
    {
        foreach ([3, 4, 5, 6, 7] as $players) {
            $state = $this->engine->deal(11, $players);

            self::assertSame(52, array_sum(array_map('count', $state->hands)));
            self::assertLessThanOrEqual(1, max(array_map('count', $state->hands)) - min(array_map('count', $state->hands)));
            self::assertContains(PresidentEngine::FIRST_CARD, $state->hands[$state->turn]);
            self::assertSame(PresidentState::PLAY, $state->phase);
            self::assertNull($state->roles);
        }

        self::assertSame($this->engine->deal(11, 4)->hands, $this->engine->deal(11, 4)->hands);
    }

    public function testSameCountAndStrictlyStronger(): void
    {
        $state = $this->board([['pique-9', 'coeur-9', 'pique-4'], ['carreau-9', 'trefle-9', 'pique-roi', 'coeur-roi'], ['pique-dame', 'coeur-dame', 'pique-5']]);

        $this->refused('Posez des cartes de même valeur.', fn () => $this->engine->play($state, 0, ['pique-9', 'pique-4']));
        $this->engine->play($state, 0, ['pique-9', 'coeur-9']);
        self::assertSame(1, $state->turn);

        $this->refused('Il faut poser 2 cartes (une paire), comme la pose à battre.', fn () => $this->engine->play($state, 1, ['pique-roi']));
        $this->refused('Il faut des cartes plus fortes que les 9.', fn () => $this->engine->play($state, 1, ['carreau-9', 'trefle-9']));
        $this->refused('Ce n’est pas votre tour.', fn () => $this->engine->play($state, 2, ['pique-dame', 'coeur-dame']));
        $this->refused('Cette carte n’est pas dans votre main.', fn () => $this->engine->play($state, 1, ['pique-2', 'coeur-2']));

        $this->engine->play($state, 1, ['pique-roi', 'coeur-roi']);
        self::assertSame(['ranks' => [], 'count' => 2], $this->engine->options($state, 2));
    }

    public function testTheTrickEndsWhenEveryoneElsePassedAndThePassedPlayerCanPlayAgain(): void
    {
        $state = $this->board([['pique-5', 'pique-roi'], ['coeur-7', 'coeur-as'], ['carreau-8', 'carreau-2']]);

        $this->engine->play($state, 0, ['pique-5']);
        $this->engine->pass($state, 1);
        $this->engine->play($state, 2, ['carreau-8']);
        $this->engine->play($state, 0, ['pique-roi']);
        self::assertSame([0], $state->order, 'Le joueur 0 n’a plus de cartes');
        // Le joueur 1 a passé, mais il peut rejouer à son tour dans le même pli
        $this->engine->play($state, 1, ['coeur-as']);
        self::assertSame(['ranks' => ['2'], 'count' => 1], $this->engine->options($state, 2));

        $this->engine->pass($state, 2);
        self::assertSame([], $state->trick, 'Tous les autres ont passé : le pli est écarté');
        self::assertCount(4, $state->cleared, 'Le pli écarté reste visible jusqu’à la pose suivante');
        self::assertSame(1, $state->turn, 'Le dernier à avoir posé reprend la main');
    }

    public function testWhenTheLastPlayerToPlayIsOutTheNextOneLeads(): void
    {
        $state = $this->board([['pique-as'], ['coeur-7', 'coeur-3'], ['carreau-8', 'carreau-4'], ['trefle-9', 'trefle-5']]);

        $this->engine->play($state, 0, ['pique-as']);
        self::assertSame([0], $state->order);
        $this->engine->pass($state, 1);
        $this->engine->pass($state, 2);
        $this->engine->pass($state, 3);

        self::assertSame([], $state->trick);
        self::assertSame(1, $state->turn);
        $this->refused('Vous avez la main : posez une ou plusieurs cartes.', fn () => $this->engine->pass($state, 1));
    }

    public function testTheRoundEndsWithOnePlayerLeftAndOnePointPerPlayerBeaten(): void
    {
        $state = $this->board([['pique-2'], ['coeur-as', 'coeur-3'], ['carreau-roi', 'carreau-4']]);

        $this->engine->play($state, 0, ['pique-2']);
        $this->engine->pass($state, 1);
        $this->engine->pass($state, 2);
        $this->engine->play($state, 1, ['coeur-as']);
        $this->engine->pass($state, 2);
        $this->engine->play($state, 1, ['coeur-3']);

        self::assertTrue($state->isOver());
        self::assertSame([0, 1, 2], $state->order);
        self::assertSame([2, 1, 0], $state->points);
        self::assertSame([PresidentState::PRESIDENT, PresidentState::NEUTRAL, PresidentState::POOR], PresidentState::rolesFor($state->order));
        self::assertSame(
            [PresidentState::VICE_POOR, PresidentState::PRESIDENT, PresidentState::POOR, PresidentState::VICE_PRESIDENT],
            PresidentState::rolesFor([1, 3, 0, 2]),
        );
    }

    public function testTheExchangeOfCardsAtTheNextRound(): void
    {
        // Ordre de la manche précédente : 2 Président, 0 Vice-président, 3 Vice-pauvre, 1 Pauvre
        $state = $this->engine->deal(5, 4, [2, 0, 3, 1]);
        $before = $this->engine->deal(5, 4);

        self::assertSame(PresidentState::EXCHANGE, $state->phase);
        self::assertSame(1, $state->turn, 'Le Pauvre commence');
        self::assertSame(\array_slice($before->hands[1], -2), $state->given[1], 'Le Pauvre donne ses 2 meilleures cartes');
        self::assertSame($state->given[1], $state->received[2]);
        self::assertSame(\array_slice($before->hands[3], -1), $state->received[0], 'Le Vice-pauvre donne sa meilleure carte');
        self::assertSame([0 => 1, 1 => 0, 2 => 2, 3 => 0], $state->toGive);

        $this->refused('Attendez la fin de l’échange des cartes.', fn () => $this->engine->play($state, 1, [$state->hands[1][0]]));
        $this->refused('Choisissez 2 cartes à donner.', fn () => $this->engine->give($state, 2, [$state->hands[2][0]]));
        $this->refused('Vous n’avez pas de carte à donner.', fn () => $this->engine->give($state, 3, [$state->hands[3][0]]));

        $gift = [$state->hands[2][0], $state->hands[2][1]];
        $this->engine->give($state, 2, $gift);
        self::assertSame(PresidentState::EXCHANGE, $state->phase);
        self::assertContains($gift[0], $state->hands[1]);

        $this->engine->autoExchange($state);
        self::assertSame(PresidentState::PLAY, $state->phase);
        self::assertSame(52, array_sum(array_map('count', $state->hands)));
        self::assertSame([13, 13, 13, 13], array_map('count', $state->hands));
    }

    public function testRobotsFinishWholeRoundsAtEveryTableSize(): void
    {
        foreach ([3, 4, 5, 6, 7] as $players) {
            $order = null;

            for ($round = 0; $round < 3; ++$round) {
                $state = $this->engine->deal(100 + $round, $players, $order);

                for ($moves = 0; !$state->isOver() && $moves < 2000; ++$moves) {
                    $this->engine->autoPlay($state);
                }

                self::assertTrue($state->isOver());
                self::assertSame(range(0, $players - 1), $this->sorted($state->order), 'Chaque joueur a une place');
                self::assertSame(array_sum(range(0, $players - 1)), array_sum($state->points));
                $order = $state->order;
            }
        }
    }

    /**
     * @param list<int> $order
     *
     * @return list<int>
     */
    private function sorted(array $order): array
    {
        sort($order);

        return $order;
    }

    /**
     * Mains choisies ; le joueur 0 a la main.
     *
     * @param list<list<string>> $hands
     */
    private function board(array $hands): PresidentState
    {
        $state = new PresidentState(\count($hands));
        $state->hands = array_map(PresidentCard::sort(...), $hands);

        return $state;
    }

    private function refused(string $message, \Closure $action): void
    {
        try {
            $action();
            self::fail('Le coup aurait dû être refusé : '.$message);
        } catch (PresidentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
