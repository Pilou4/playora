<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Spider;

use App\Game\Spider\SpiderBot;
use App\Game\Spider\SpiderCard;
use App\Game\Spider\SpiderEngine;
use App\Game\Spider\SpiderException;
use App\Game\Spider\SpiderLevel;
use App\Game\Spider\SpiderState;
use PHPUnit\Framework\TestCase;

/**
 * Spider débutant, Spider et Spider Pro (règles et barèmes GameDuell).
 */
final class SpiderEngineTest extends TestCase
{
    public function testTheDeal(): void
    {
        foreach (SpiderLevel::cases() as $level) {
            $state = (new SpiderEngine())->deal(42, $level);

            // 54 cartes sur les 10 piles (6 sur les 4 premières, 5 sur les autres), la dernière visible
            self::assertSame([6, 6, 6, 6, 5, 5, 5, 5, 5, 5], array_map('count', $state->columns));

            foreach ($state->columns as $column) {
                self::assertSame(\count($column) - 1, \count(array_filter($column, static fn (array $entry): bool => !$entry[1])));
            }

            // 50 cartes en 5 piles talon de 10
            self::assertSame([10, 10, 10, 10, 10], array_map('count', $state->stock));
            self::assertCount($level->jokers(), $state->jokers);

            $all = [...array_merge(...$state->stock), ...array_column(array_merge(...$state->columns), 0)];
            self::assertCount(104, array_unique($all));
            $suits = array_unique(array_map(static fn (string $code): string => SpiderCard::fromCode($code)->suit, $all));
            sort($suits);
            $expected = $level->suits();
            sort($expected);
            self::assertSame($expected, $suits);
        }
    }

    public function testEachLevelHasItsSuitsAndJokers(): void
    {
        self::assertSame(['pique'], SpiderLevel::Beginner->suits());
        self::assertSame(['pique', 'coeur'], SpiderLevel::Classic->suits());
        self::assertCount(4, SpiderLevel::Pro->suits());
        self::assertSame(1, SpiderLevel::Classic->jokers());
        self::assertSame(2, SpiderLevel::Pro->jokers());

        foreach (SpiderLevel::cases() as $level) {
            $deck = SpiderCard::deck($level);
            self::assertCount(104, $deck);
            self::assertCount(104, array_unique(array_map(static fn (SpiderCard $card): string => $card->code(), $deck)));
        }
    }

    public function testTheSameSeedGivesTheSameCardsToEveryone(): void
    {
        $engine = new SpiderEngine();

        self::assertSame($engine->deal(7, SpiderLevel::Pro)->toArray(), $engine->deal(7, SpiderLevel::Pro)->toArray());
        self::assertNotSame($engine->deal(7, SpiderLevel::Pro)->stock, $engine->deal(8, SpiderLevel::Pro)->stock);
    }

    public function testCodes(): void
    {
        $card = SpiderCard::fromCode('coeur-dame-3');

        self::assertSame(['coeur', 12, 3], [$card->suit, $card->rank, $card->copy]);
        self::assertSame('coeur-dame-3', $card->code());
        self::assertSame('coeur/dame', $card->face());

        $this->expectException(SpiderException::class);
        SpiderCard::fromCode('coeur-1-1');
    }

    public function testACardGoesOnTheNextRankWhateverItsSuit(): void
    {
        $engine = new SpiderEngine();
        $state = $this->board(SpiderLevel::Pro, [[['pique-10-1', true]], [['coeur-9-1', true]], [['carreau-8-1', true]], [['trefle-7-1', true]]]);

        $engine->move($state, 'coeur-9-1', 'colonne-0');
        self::assertSame([['pique-10-1', true], ['coeur-9-1', true]], $state->columns[0]);

        // Un 7 ne va pas sur un 9
        $this->expectException(SpiderException::class);
        $engine->move($state, 'trefle-7-1', 'colonne-0');
    }

    public function testAnyCardGoesOnAnEmptyColumn(): void
    {
        $engine = new SpiderEngine();
        $state = $this->board(SpiderLevel::Classic, [[['pique-dame-1', false], ['coeur-4-1', true]]]);

        $engine->move($state, 'coeur-4-1', 'colonne-5');

        self::assertSame([['coeur-4-1', true]], $state->columns[5]);
        // La carte cachée s'est retournée
        self::assertTrue($state->columns[0][0][1]);
        self::assertSame(SpiderLevel::Classic->revealPoints(), $state->score);
    }

    public function testOnlyASameSuitSequenceMovesAsAGroup(): void
    {
        $engine = new SpiderEngine();
        $state = $this->board(SpiderLevel::Classic, [
            [['pique-9-1', true], ['pique-8-1', true], ['pique-7-1', true]],
            [['coeur-10-1', true]],
            [['coeur-9-1', true], ['pique-8-2', true]],
        ]);

        // 9, 8, 7 de pique : le groupe part ensemble
        $engine->move($state, 'pique-9-1', 'colonne-1');
        self::assertCount(4, $state->columns[1]);

        // 9 de cœur et 8 de pique : pas de la même couleur, le groupe ne bouge pas
        $this->expectException(SpiderException::class);
        $this->expectExceptionMessage('même couleur');
        $engine->move($state, 'coeur-9-1', 'colonne-0');
    }

    public function testACompleteFamilyGoesToTheBaseAndPays(): void
    {
        $engine = new SpiderEngine();
        $family = array_map(static fn (int $rank): array => [(new SpiderCard('pique', $rank, 1))->code(), true], range(13, 2));
        $state = $this->board(SpiderLevel::Beginner, [[['pique-5-2', false], ...$family], [['pique-as-1', true]]]);

        $engine->move($state, 'pique-as-1', 'colonne-0');

        self::assertSame(['pique'], $state->foundations);
        self::assertSame([['pique-5-2', true]], $state->columns[0]);
        // Famille + carte retournée + pile 1 vidée (une fois)
        self::assertSame(100 + 20 + 100, $state->score);
        self::assertContains(1, $state->emptied);
    }

    public function testAnEmptiedColumnPaysOnlyOnce(): void
    {
        $engine = new SpiderEngine();
        $state = $this->board(SpiderLevel::Beginner, [[['pique-5-1', true]], [['pique-6-1', true]], [['pique-7-1', true]]]);

        $engine->move($state, 'pique-5-1', 'colonne-1');
        $engine->move($state, 'pique-5-1', 'colonne-0');
        $engine->move($state, 'pique-5-1', 'colonne-1');

        self::assertSame(100, $state->score);
    }

    public function testTheJokerHoldsOneCardAtATime(): void
    {
        $engine = new SpiderEngine();
        $state = $this->board(SpiderLevel::Classic, [[['pique-roi-1', true], ['coeur-3-1', true]], [['pique-9-1', true], ['pique-8-1', true]], [['coeur-4-1', true]]]);

        $engine->move($state, 'coeur-3-1', 'joker-0');
        self::assertSame(['coeur-3-1'], $state->jokers);
        self::assertTrue($state->jokerUsed);

        // Elle revient sur une pile quand on veut
        $engine->move($state, 'coeur-3-1', 'colonne-2');
        self::assertSame([null], $state->jokers);

        // Un groupe ne va pas sur le Joker
        $this->expectException(SpiderException::class);
        $engine->move($state, 'pique-9-1', 'joker-0');
    }

    public function testTheJokerMustBeEmptiedFirstAndProHasTwo(): void
    {
        $engine = new SpiderEngine();
        $state = $this->board(SpiderLevel::Pro, [[['pique-2-1', true]], [['coeur-3-1', true]], [['trefle-4-1', true]]]);

        $engine->move($state, 'pique-2-1', 'joker-0');
        $engine->move($state, 'coeur-3-1', 'joker-1');
        self::assertSame(['pique-2-1', 'coeur-3-1'], $state->jokers);

        $this->expectException(SpiderException::class);
        $this->expectExceptionMessage('Videz');
        $engine->move($state, 'trefle-4-1', 'joker-0');
    }

    public function testDealingNeedsEveryColumnFilled(): void
    {
        $engine = new SpiderEngine();
        $state = $engine->deal(3, SpiderLevel::Classic);
        $pile = $state->stock[2];

        $engine->dealStock($state, 2);

        self::assertSame([], $state->stock[2]);

        foreach ($state->columns as $index => $column) {
            self::assertSame([$pile[$index], true], $column[\count($column) - 1]);
        }

        $this->expectException(SpiderException::class);
        $this->expectExceptionMessage('vide');
        $engine->dealStock($state, 2);
    }

    public function testNoDealOnAnEmptyColumn(): void
    {
        $engine = new SpiderEngine();
        $state = $this->board(SpiderLevel::Beginner, [[['pique-5-1', true]]]);
        $state->stock = [array_map(static fn (int $i): string => 'pique-2-'.(1 + $i % 8), range(0, 9))];

        self::assertFalse($engine->canDealStock($state));
        $this->expectException(SpiderException::class);
        $this->expectExceptionMessage('au moins une carte');
        $engine->dealStock($state, 0);
    }

    public function testUndoCostsAndNeedsEnoughPoints(): void
    {
        $engine = new SpiderEngine();
        $state = $this->board(SpiderLevel::Beginner, [[['pique-9-1', false], ['pique-5-1', true]], [['pique-6-1', true]]]);

        // Pas encore de points : pas d'annulation
        $engine->move($state, 'pique-5-1', 'colonne-1');
        self::assertSame(20, $state->score);

        try {
            $engine->undo($state);
            self::fail('Annulation acceptée sans assez de points.');
        } catch (SpiderException $exception) {
            self::assertStringContainsString('50 points', $exception->getMessage());
        }

        // Avec assez de points : la position d'avant revient, score compris, moins 50
        $state->score = 120;
        $state->history[\count($state->history) - 1]['score'] = 100;
        $engine->undo($state);

        self::assertSame(50, $state->score);
        self::assertSame([['pique-9-1', false], ['pique-5-1', true]], $state->columns[0]);
    }

    public function testUndoNeverPaysTheSameFamilyTwice(): void
    {
        $engine = new SpiderEngine();
        $family = array_map(static fn (int $rank): array => [(new SpiderCard('pique', $rank, 1))->code(), true], range(13, 2));
        $state = $this->board(SpiderLevel::Beginner, [$family, [['pique-as-1', true], ['pique-as-2', true]]]);
        $state->score = 1000;

        $engine->move($state, 'pique-as-2', 'colonne-0');
        $afterFamily = $state->score;
        $engine->undo($state);
        $engine->move($state, 'pique-as-2', 'colonne-0');

        // Refaire la famille après une annulation ne rapporte rien de plus : l'annulation coûte
        self::assertSame($afterFamily - 50, $state->score);
    }

    public function testFinishingBonusesPerLevel(): void
    {
        $engine = new SpiderEngine();

        $beginner = new SpiderState(1, SpiderLevel::Beginner);
        self::assertSame(250 + 100 + 60 * 5, $engine->finish($beginner, 60, true));

        $withJoker = new SpiderState(1, SpiderLevel::Classic);
        $withJoker->jokerUsed = true;
        self::assertSame(250 + 60 * 5, $engine->finish($withJoker, 60, true));

        $pro = new SpiderState(1, SpiderLevel::Pro);
        self::assertSame(20000 + 60 * 50, $engine->finish($pro, 60, true));

        // Arrêter avant la fin : pas de bonus
        $stopped = new SpiderState(1, SpiderLevel::Pro);
        self::assertSame(0, $engine->finish($stopped, 200, false));
        self::assertTrue($stopped->finished);
    }

    public function testTargetsAreAcceptedByTheEngine(): void
    {
        $engine = new SpiderEngine();

        foreach (SpiderLevel::cases() as $level) {
            $state = $engine->deal(11, $level);
            $engine->dealStock($state, 0);

            foreach ($engine->targets($state) as $code => $places) {
                foreach ($places as $place) {
                    $copy = SpiderState::fromArray($state->toArray());
                    $engine->move($copy, $code, $place);
                }
            }
        }

        self::assertTrue(true);
    }

    public function testStateSurvivesTheSaving(): void
    {
        $engine = new SpiderEngine();
        $state = $engine->deal(5, SpiderLevel::Pro);
        $engine->dealStock($state, 1);

        self::assertSame($state->toArray(), SpiderState::fromArray(json_decode(json_encode($state->toArray(), \JSON_THROW_ON_ERROR), true))->toArray());
    }

    public function testTheBotPlaysAWholeDealAndStops(): void
    {
        $engine = new SpiderEngine();
        $bot = new SpiderBot($engine);

        foreach (SpiderLevel::cases() as $level) {
            foreach (range(1, 15) as $seed) {
                $state = $engine->deal($seed, $level);
                $seconds = $bot->play($state);

                self::assertTrue($state->finished);
                self::assertLessThanOrEqual(300, $seconds);
                self::assertFalse($state->jokerUsed);
                self::assertSame(0, $state->undos);
            }
        }
    }

    /**
     * Une position de test : les piles données, les autres vides (déjà comptées comme vidées), talon vide.
     *
     * @param list<list<array{string, bool}>> $columns
     */
    private function board(SpiderLevel $level, array $columns): SpiderState
    {
        $state = new SpiderState(1, $level);
        $state->columns = array_replace(array_fill(0, 10, []), $columns);
        $state->stock = [];
        // Les piles vides de départ ne rapportent rien : seules celles qu'on vide en jouant comptent
        $state->emptied = array_keys(array_filter($state->columns, static fn (array $column): bool => [] === $column));

        return $state;
    }
}
