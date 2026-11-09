<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Belote;

use App\Game\Belote\BeloteMemory;
use App\Game\Belote\BeloteState;
use App\Game\Belote\Phase;
use App\Game\Belote\Suit;
use PHPUnit\Framework\TestCase;

final class BeloteMemoryTest extends TestCase
{
    use CardsTrait;

    public function testCardIsMasterOnceTheHigherOnesArePlayed(): void
    {
        $state = $this->state(['pique-10', 'trefle-7'], [1, 'pique-as', 2, 'pique-7', 3, 'pique-8', 0, 'trefle-8']);
        $memory = BeloteMemory::of($state, 0);

        self::assertTrue($memory->isMaster(self::c('pique-10')));
        self::assertFalse($memory->isMaster(self::c('trefle-7')));
    }

    public function testPlayerWhoDoesNotFollowHasNoMoreOfThatSuit(): void
    {
        // La place 3 ne fournit pas à pique et ne coupe pas alors que l'adversaire (place 2) est maître :
        // elle n'a plus ni pique ni atout
        $state = $this->state(['coeur-7'], [2, 'pique-as', 3, 'trefle-8', 0, 'pique-8', 1, 'pique-7']);
        $memory = BeloteMemory::of($state, 0);

        self::assertTrue($memory->isVoid(3, Suit::Spades));
        self::assertTrue($memory->isVoid(3, Suit::Hearts));
        self::assertFalse($memory->isVoid(2, Suit::Spades));
    }

    public function testDiscardingOnPartnersTrickSaysNothingAboutTrumps(): void
    {
        // La place 3 se défausse sur le pli de son partenaire (place 1) : elle peut encore avoir de l'atout
        $state = $this->state(['coeur-7'], [1, 'pique-as', 2, 'pique-7', 3, 'trefle-8', 0, 'pique-8']);
        $state->history = self::trick(2, 'pique-7', 3, 'pique-as', 0, 'pique-8', 1, 'trefle-8');
        $memory = BeloteMemory::of($state, 0);

        self::assertTrue($memory->isVoid(1, Suit::Spades));
        self::assertFalse($memory->isVoid(1, Suit::Hearts));
    }

    public function testWinningCardIsSafeOnlyIfNobodyCanBeatIt(): void
    {
        $state = $this->state(['pique-roi'], [2, 'carreau-as', 3, 'pique-9', 0, 'carreau-8', 1, 'carreau-7']);
        $memory = BeloteMemory::of($state, 0);

        // La place 3 n'a plus de carreau, et elle n'a pas coupé l'As adverse : plus d'atout non plus
        self::assertFalse($memory->canBeBeaten(self::c('carreau-10'), Suit::Diamonds, [3]));
        // La place 1 peut encore fournir un carreau plus fort que le 10 ? Non : l'As est tombé
        self::assertFalse($memory->canBeBeaten(self::c('carreau-10'), Suit::Diamonds, [1]));
        // Le Roi de carreau, lui, peut tomber sous le 10 encore caché
        self::assertTrue($memory->canBeBeaten(self::c('carreau-roi'), Suit::Diamonds, [1]));
    }

    /**
     * Donne en cours, atout cœur, vue de la place 0.
     *
     * @param list<string>     $hand
     * @param list<int|string> $history
     */
    private function state(array $hand, array $history): BeloteState
    {
        $state = new BeloteState(1001, 3);
        $state->phase = Phase::Playing;
        $state->trump = Suit::Hearts;
        $state->taker = 0;
        $state->hands[0] = self::cs(...$hand);
        $state->history = self::trick(...$history);

        return $state;
    }
}
