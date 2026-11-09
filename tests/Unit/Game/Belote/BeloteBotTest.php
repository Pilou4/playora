<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Belote;

use App\Game\Belote\BeloteBot;
use App\Game\Belote\BeloteEngine;
use App\Game\Belote\BeloteScorer;
use App\Game\Belote\BeloteState;
use App\Game\Belote\Phase;
use App\Game\Belote\Suit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

final class BeloteBotTest extends TestCase
{
    use CardsTrait;

    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        foreach ([1, 7, 42, 2026, 31337] as $seed) {
            yield "graine $seed" => [$seed];
        }
    }

    /**
     * Quatre robots jouent une partie entière : ils ne font jamais de coup interdit
     * (le moteur lèverait une exception) et chaque donne compte bien 162 points.
     */
    #[DataProvider('seeds')]
    public function testFourBotsPlayAWholeGame(int $seed): void
    {
        $engine = new BeloteEngine(new Randomizer(new Xoshiro256StarStar($seed)));
        $bot = new BeloteBot($engine);
        [$state] = $engine->start(1001, 0);

        for ($actions = 0; Phase::Finished !== $state->phase; ++$actions) {
            self::assertLessThan(3000, $actions, 'La partie doit se terminer.');
            // Comme en vrai : l'état repasse par la base entre deux coups
            $state = BeloteState::fromArray($state->toArray());

            foreach ($bot->act($state)->deals as $deal) {
                self::assertSame(BeloteScorer::DEAL_POINTS, array_sum($deal->cardPoints));
            }
        }

        self::assertGreaterThanOrEqual(1001, max($state->scores));
        self::assertNotNull($state->winnerTeam);
    }

    public function testBotTakesWithAStrongHand(): void
    {
        $state = $this->bidding(['coeur-valet', 'coeur-9', 'coeur-as', 'pique-as', 'trefle-7'], 'coeur-10');

        (new BeloteBot(new BeloteEngine()))->act($state);

        self::assertSame(0, $state->taker);
        self::assertSame(Suit::Hearts, $state->trump);
    }

    public function testBotPassesWithAWeakHand(): void
    {
        $state = $this->bidding(['coeur-7', 'pique-8', 'trefle-9', 'carreau-dame', 'pique-roi'], 'coeur-8');

        (new BeloteBot(new BeloteEngine()))->act($state);

        self::assertNull($state->taker);
        self::assertSame(1, $state->turn);
    }

    public function testTakerDrawsTrumpsWithHisMasterTrump(): void
    {
        $state = $this->playing(0, ['coeur-valet', 'coeur-8', 'pique-as', 'trefle-7'], []);

        self::assertSame('coeur-valet', $this->played($state));
    }

    public function testTakerWithoutTheJackLeadsASmallTrumpToDrawTheBigOnes(): void
    {
        $state = $this->playing(0, ['coeur-dame', 'coeur-8', 'pique-as', 'trefle-7'], []);

        self::assertSame('coeur-8', $this->played($state));
    }

    public function testTakersPartnerLeadsTheJackStraightAway(): void
    {
        $state = $this->playing(2, ['coeur-valet', 'coeur-7', 'pique-as', 'trefle-7'], []);

        self::assertSame('coeur-valet', $this->played($state));
    }

    public function testDefenderNeverLeadsTrump(): void
    {
        $state = $this->playing(1, ['coeur-valet', 'coeur-9', 'pique-7', 'trefle-8'], []);

        self::assertFalse(str_starts_with($this->played($state), 'coeur'));
    }

    public function testBotCashesAnAceNobodyCanTrump(): void
    {
        // Les défenseurs (place 1, preneur 0) n'entament pas à l'atout et encaissent leur As
        $state = $this->playing(0, ['pique-as', 'pique-7', 'trefle-8', 'coeur-7'], [], turn: 1);

        self::assertSame('pique-as', $this->played($state));
    }

    public function testBotLoadsTheTrickItsPartnerHasSurelyWon(): void
    {
        // Le partenaire (place 2) a joué l'As de pique, la place 3 a fourni : la place 0 est dernière
        $state = $this->playing(0, ['pique-10', 'pique-7', 'trefle-8'], [1, 'pique-8', 2, 'pique-as', 3, 'pique-9'], turn: 0);

        self::assertSame('pique-10', $this->played($state));
    }

    public function testBotDoesNotThrowPointsOnALostTrick(): void
    {
        // L'adversaire a joué l'As de trèfle : sans trèfle ni atout, la place 0 se défausse d'une petite carte
        // et garde le 7 de carreau qui protège son 10
        $state = $this->playing(2, ['carreau-10', 'carreau-7', 'pique-8'], [3, 'trefle-as'], turn: 0);

        self::assertSame('pique-8', $this->played($state));
    }

    public function testBotWinsWithTheCheapestSureCard(): void
    {
        // La place 0 joue en dernier : elle prend avec la plus petite carte qui gagne
        $state = $this->playing(1, ['pique-as', 'pique-roi', 'pique-7'], [1, 'pique-dame', 2, 'pique-8', 3, 'pique-9'], turn: 0);

        self::assertSame('pique-roi', $this->played($state));
    }

    /**
     * Donne en cours, atout cœur. Les cartes des autres joueurs ne servent qu'à compléter le jeu.
     *
     * @param list<string>     $hand
     * @param list<int|string> $trick
     */
    private function playing(int $taker, array $hand, array $trick, int $turn = 0): BeloteState
    {
        $state = new BeloteState(1001, 3);
        $state->phase = Phase::Playing;
        $state->trump = Suit::Hearts;
        $state->taker = $taker;
        $state->turn = $turn;
        $state->hands[$turn] = self::cs(...$hand);
        $state->trick = self::trick(...$trick);
        $state->history = $state->trick;

        return $state;
    }

    private function played(BeloteState $state): string
    {
        $position = $state->turn;
        $events = (new BeloteBot(new BeloteEngine()))->act($state)->events;
        $played = array_values(array_filter($events, static fn (array $event): bool => 'played' === $event['type'] && $event['position'] === $position));

        return $played[0]['card'];
    }

    /**
     * @param list<string> $hand
     */
    private function bidding(array $hand, string $turned): BeloteState
    {
        $state = new BeloteState(1001, 3);
        $state->hands[0] = self::cs(...$hand);
        $state->turnedCard = self::c($turned);
        $state->turn = 0;
        // 11 cartes restantes, n'importe lesquelles (non utilisées par ces tests)
        $state->stock = self::cs('pique-7', 'pique-9', 'pique-10', 'trefle-8', 'trefle-10', 'trefle-valet', 'carreau-7', 'carreau-8', 'carreau-9', 'carreau-10', 'carreau-as');

        return $state;
    }
}
