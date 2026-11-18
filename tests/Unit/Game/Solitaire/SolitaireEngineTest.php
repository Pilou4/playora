<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Solitaire;

use App\Game\Solitaire\SolitaireCard;
use App\Game\Solitaire\SolitaireEngine;
use App\Game\Solitaire\SolitaireException;
use App\Game\Solitaire\SolitaireRules;
use App\Game\Solitaire\SolitaireScore;
use App\Game\Solitaire\SolitaireState;
use PHPUnit\Framework\TestCase;

/**
 * Solitaire Klondike (règles et barème GameDuell).
 */
final class SolitaireEngineTest extends TestCase
{
    public function testTheDeal(): void
    {
        $state = (new SolitaireEngine())->deal(42);

        self::assertSame([1, 2, 3, 4, 5, 6, 7], array_map('count', $state->tableau));
        self::assertCount(24, $state->stock);
        self::assertSame([], $state->waste);

        foreach ($state->tableau as $column) {
            // Seule la dernière carte de chaque pile est visible
            self::assertSame(\count($column) - 1, \count(array_filter($column, static fn (array $entry): bool => !$entry[1])));
            self::assertTrue($column[\count($column) - 1][1]);
        }

        $all = [...$state->stock, ...array_merge(...array_map(static fn (array $column): array => array_column($column, 0), $state->tableau))];
        self::assertCount(52, array_unique($all));
    }

    public function testTheSameSeedGivesTheSameCardsToEveryone(): void
    {
        $engine = new SolitaireEngine();

        self::assertSame($engine->deal(7)->toArray(), $engine->deal(7)->toArray());
        self::assertNotSame($engine->deal(7)->stock, $engine->deal(8)->stock);
    }

    public function testCodes(): void
    {
        foreach (SolitaireCard::deck() as $card) {
            self::assertTrue(SolitaireCard::fromCode($card->code())->equals($card));
        }

        self::assertSame('coeur-as', (new SolitaireCard('coeur', 1))->code());

        $this->expectException(SolitaireException::class);
        SolitaireCard::fromCode('coeur-1');
    }

    public function testDrawingOneCardAtATimeAndTheStockPenalty(): void
    {
        $engine = new SolitaireEngine();
        $state = $this->board(stock: ['pique-2', 'coeur-3']);
        $state->score = 1000;

        $engine->draw($state);
        self::assertSame(['coeur-3'], $state->waste);
        $engine->draw($state);

        // Trois passages gratuits (le premier et deux remises en place), puis 400 points par nouvelle consultation ;
        // le score ne descend jamais sous 0
        $scores = [];

        foreach (range(1, 4) as $pass) {
            $engine->draw($state);
            $scores[] = $state->score;
            $engine->draw($state);
            $engine->draw($state);
        }

        self::assertSame([1000, 1000, 600, 200], $scores);
        $engine->draw($state);
        self::assertSame(0, $state->score);
    }

    public function testTableauRulesAlternateColoursAndDescending(): void
    {
        $engine = new SolitaireEngine();
        $state = $this->board(tableau: [[['pique-10', true]], [['coeur-9', true]], [['carreau-9', true]], [['trefle-9', true]], [], [], []]);

        $engine->move($state, 'coeur-9', 'colonne-0');
        self::assertSame([['pique-10', true], ['coeur-9', true]], $state->tableau[0]);

        $this->expectException(SolitaireException::class);
        $engine->move($state, 'trefle-9', 'colonne-0');
    }

    public function testOnlyAKingOnAnEmptyColumn(): void
    {
        $engine = new SolitaireEngine();
        $state = $this->board(tableau: [[['coeur-dame', true]], [], [], [], [], [], [['pique-roi', true]]]);

        $engine->move($state, 'pique-roi', 'colonne-1');
        self::assertSame([['pique-roi', true]], $state->tableau[1]);

        $this->expectException(SolitaireException::class);
        $engine->move($state, 'coeur-dame', 'colonne-2');
    }

    public function testABlockMovesTogetherAndTheHiddenCardIsRevealed(): void
    {
        $engine = new SolitaireEngine();
        $state = $this->board(tableau: [[['carreau-roi', false], ['pique-dame', true], ['coeur-valet', true]], [['coeur-roi', true]], [], [], [], [], []]);

        $engine->move($state, 'pique-dame', 'colonne-1');

        self::assertSame([['coeur-roi', true], ['pique-dame', true], ['coeur-valet', true]], $state->tableau[1]);
        self::assertSame([['carreau-roi', true]], $state->tableau[0]);
        self::assertSame(SolitaireScore::REVEAL, $state->score);
    }

    public function testFoundationsStartWithTheAceAndCountWhileTheCardsAreThere(): void
    {
        $engine = new SolitaireEngine();
        $state = $this->board(waste: ['coeur-2', 'coeur-as'], tableau: [[['pique-3', true]], [], [], [], [], [], []]);

        $engine->toFoundation($state, 'coeur-as');
        $engine->toFoundation($state, 'coeur-2');
        self::assertSame(2, $state->foundations['coeur']);
        self::assertSame(2000, $state->score);

        // Le 2 revient sur une pile de tri : il reperd ses points ; il repart sur sa famille : il les regagne
        $engine->move($state, 'coeur-2', 'colonne-0');
        self::assertSame(1000, $state->score);
        $engine->toFoundation($state, 'coeur-2');
        self::assertSame(2000, $state->score);

        $this->expectException(SolitaireException::class);
        $engine->toFoundation($state, 'pique-3');
    }

    public function testUndoCosts1000AndCancelsOnlyTheLastCardMove(): void
    {
        $engine = new SolitaireEngine();
        $state = $this->board(waste: ['coeur-dame'], tableau: [[['pique-roi', true]], [['trefle-valet', true]], [], [], [], [], []]);
        $state->score = 5000;

        $engine->move($state, 'coeur-dame', 'colonne-0');
        $engine->move($state, 'trefle-valet', 'colonne-0');
        $engine->undo($state);

        // Seul le dernier déplacement est annulé
        self::assertSame([['trefle-valet', true]], $state->tableau[1]);
        self::assertSame([['pique-roi', true], ['coeur-dame', true]], $state->tableau[0]);
        self::assertSame(4000, $state->score);

        // Le retour annule aussi les points du déplacement : une carte montée puis annulée ne compte plus
        $state = $this->board(waste: ['coeur-as']);
        $state->score = 5000;
        $engine->toFoundation($state, 'coeur-as');
        $engine->undo($state);
        self::assertSame(4000, $state->score);
        self::assertSame(0, $state->foundations['coeur']);
        $state = $this->board(waste: ['coeur-dame'], tableau: [[['pique-roi', true]], [['trefle-valet', true]], [], [], [], [], []]);
        $state->score = 5000;
        $engine->move($state, 'coeur-dame', 'colonne-0');
        $engine->move($state, 'trefle-valet', 'colonne-0');
        $engine->undo($state);
        self::assertSame(1, $state->undos);

        // Un seul retour à la fois
        $this->expectException(SolitaireException::class);
        $engine->undo($state);
    }

    public function testAScoreIsNeverNegative(): void
    {
        $engine = new SolitaireEngine();
        $state = $this->board(waste: ['coeur-dame'], tableau: [[['pique-roi', true]], [], [], [], [], [], []]);

        // 0 point : pas de retour possible (il coûte 1 000 points)
        $engine->move($state, 'coeur-dame', 'colonne-0');
        self::assertFalse($engine->canUndo($state));

        try {
            $engine->undo($state);
            self::fail('Le retour aurait dû être refusé.');
        } catch (SolitaireException $exception) {
            self::assertSame('Pas assez de points pour revenir en arrière (il en faut 1 000).', $exception->getMessage());
        }

        // Talon repassé au-delà des passages gratuits : le score s'arrête à zéro
        $state = $this->board(waste: ['pique-2', 'coeur-3']);
        $state->passes = 10;
        $engine->draw($state);
        self::assertSame(0, $state->score);
    }

    public function testTheStockCannotBeUndone(): void
    {
        $engine = new SolitaireEngine();
        $state = $this->board(stock: ['pique-2', 'coeur-3'], waste: ['coeur-dame'], tableau: [[['pique-roi', true]], [], [], [], [], [], []]);

        // Une carte déplacée, puis la pioche : plus de retour possible (ni sur la pioche, ni sur le coup d'avant)
        $engine->move($state, 'coeur-dame', 'colonne-0');
        $engine->draw($state);
        self::assertSame([], $state->history);

        $this->expectException(SolitaireException::class);
        $engine->undo($state);
    }

    public function testUndoAlsoCancelsTheAutomaticMovesOfThatMove(): void
    {
        $engine = new SolitaireEngine();
        // Le 10 part sur le Valet et découvre l'As de pique, qui monte seul
        $state = $this->board(tableau: [[['coeur-valet', true]], [['pique-as', false], ['pique-10', true]], [], [], [], [], []]);
        $state->score = 1000;

        $engine->move($state, 'pique-10', 'colonne-0');
        $engine->autoPlay($state);
        self::assertSame(1, $state->foundations['pique']);
        self::assertCount(1, $state->history);

        $engine->undo($state);
        self::assertSame(0, $state->foundations['pique']);
        self::assertSame([['pique-as', false], ['pique-10', true]], $state->tableau[1]);
    }

    public function testAutoPlayRanksOnlyCardsThatAreNoLongerNeeded(): void
    {
        $engine = new SolitaireEngine();
        $state = $this->board(tableau: [[['coeur-as', true]], [['pique-as', true]], [['coeur-2', true]], [['coeur-3', true]], [], [], []]);

        // Les As et les 2 montent toujours ; le 3 de cœur attend que les quatre 2 soient rangés
        self::assertSame(['coeur-as', 'pique-as', 'coeur-2'], $engine->autoPlay($state));
        self::assertSame([['coeur-3', true]], $state->tableau[3]);

        $state->foundations = ['pique' => 2, 'coeur' => 2, 'carreau' => 2, 'trefle' => 2];
        self::assertSame(['coeur-3'], $engine->autoPlay($state));
    }

    public function testATwoNeverGoesUpBeforeItsAce(): void
    {
        $engine = new SolitaireEngine();

        // Le 2 de carreau seul : il reste dans les cartes retournées, même avec le rangement automatique
        $state = $this->board(waste: ['carreau-2'], tableau: [[['pique-roi', true]], [], [], [], [], [], []]);
        self::assertSame([], $engine->autoPlay($state));
        self::assertSame(0, $state->foundations['carreau']);
        self::assertSame([], $engine->targets($state)['carreau-2'] ?? []);

        $this->expectException(SolitaireException::class);
        $engine->move($state, 'carreau-2', 'famille');
    }

    public function testTheAceAndTheTwoDrawnTogetherGoUpOneAfterTheOther(): void
    {
        $engine = new SolitaireEngine();
        // Le même clic retourne le 2 puis l'As de carreau (l'As dessus) : l'As monte, puis le 2 ; la page le montre dans cet ordre
        $state = $this->board(waste: ['trefle-9', 'carreau-2', 'carreau-as'], tableau: [[['pique-7', true]], [['coeur-as', true]], [], [], [], [], []]);
        $state->fan = 3;

        self::assertSame(['carreau-as', 'carreau-2', 'coeur-as'], $engine->autoPlay($state));
        self::assertSame([['carreau-as', 'talon'], ['carreau-2', 'talon'], ['coeur-as', 'colonne-1']], $state->auto);
        self::assertSame(2, $state->foundations['carreau']);
        self::assertSame($state->auto, SolitaireState::fromArray($state->toArray())->auto);
    }

    public function testHiddenCardsNeverGoUpAlone(): void
    {
        $engine = new SolitaireEngine();
        // Sous l'As de pique (seul étalé) : le 2 de pique, caché sous l'éventail. Dans la pile : l'As de cœur face cachée sous le 2 de trèfle
        $state = $this->board(waste: ['pique-2', 'pique-as'], tableau: [[['coeur-as', false], ['trefle-2', true]], [], [], [], [], [], []]);
        $state->fan = 1;
        $state->foundations['trefle'] = 1;

        // L'As de pique monte ; le 2 de pique, qu'on ne voyait pas, reste ; le 2 de trèfle monte, l'As de cœur se retourne et reste
        self::assertSame(['pique-as', 'trefle-2'], $engine->autoPlay($state));
        self::assertSame(['pique-2'], $state->waste);
        self::assertSame([['coeur-as', true]], $state->tableau[0]);
        self::assertFalse($state->finished);
    }

    public function testTheEndPlaysItselfOnceEverythingIsVisible(): void
    {
        $engine = new SolitaireEngine();
        $state = $this->board(tableau: [[['coeur-roi', true], ['pique-dame', true]], [['pique-roi', true]], [['coeur-dame', true]], [], [], [], []]);
        $state->foundations = ['pique' => 11, 'coeur' => 11, 'carreau' => 13, 'trefle' => 13];

        // La plus petite d'abord, jusqu'à la dernière ; la partie est résolue
        self::assertSame(['pique-dame', 'coeur-dame', 'coeur-roi', 'pique-roi'], $engine->autoComplete($state));
        self::assertTrue($state->finished);
        self::assertSame(52, $state->sorted());
        self::assertSame([['pique-dame', 'colonne-0'], ['coeur-dame', 'colonne-2'], ['coeur-roi', 'colonne-0'], ['pique-roi', 'colonne-1']], $state->auto);

        // Avec des cartes retournées (le talon est vide) : elles montent aussi, dans le bon ordre
        $state = $this->board(waste: ['coeur-valet'], tableau: [[['coeur-roi', true], ['pique-dame', true]], [['pique-roi', true]], [['coeur-dame', true]], [], [], [], []]);
        $state->foundations = ['pique' => 11, 'coeur' => 10, 'carreau' => 13, 'trefle' => 13];
        self::assertSame(['coeur-valet', 'pique-dame', 'coeur-dame', 'coeur-roi', 'pique-roi'], $engine->autoComplete($state));
        self::assertTrue($state->finished);

        // Bloqué (le Valet de cœur est sous la Dame de cœur dans les cartes retournées) : rien ne bouge
        $state = $this->board(waste: ['coeur-valet', 'coeur-dame'], tableau: [[['coeur-roi', true]], [], [], [], [], [], []]);
        $state->foundations = ['pique' => 13, 'coeur' => 10, 'carreau' => 13, 'trefle' => 13];
        self::assertSame([], $engine->autoComplete($state));
        self::assertSame(10, $state->foundations['coeur']);

        // Une carte encore au talon (ou cachée) : rien ne part tout seul
        $state = $this->board(stock: ['trefle-roi'], tableau: [[['pique-roi', true]], [], [], [], [], [], []]);
        $state->foundations = ['pique' => 12, 'coeur' => 13, 'carreau' => 13, 'trefle' => 12];
        self::assertSame([], $engine->autoComplete($state));
    }

    public function testDrawingThreeCardsAtATime(): void
    {
        $engine = new SolitaireEngine();
        $state = $this->board(stock: ['pique-2', 'coeur-3', 'carreau-4', 'trefle-5']);
        $state->draw = 3;

        $engine->draw($state);
        self::assertSame(['trefle-5', 'carreau-4', 'coeur-3'], $state->waste);
        self::assertSame(['coeur-3'], $engine->playableCodes($state));

        // Il n'en reste qu'une : elle est retournée, et les 2 d'avant complètent l'éventail (toujours 3 cartes, comme le vrai jeu)
        $engine->draw($state);
        self::assertSame(['trefle-5', 'carreau-4', 'coeur-3', 'pique-2'], $state->waste);
        self::assertSame(['carreau-4', 'coeur-3', 'pique-2'], $state->visibleWaste());
        self::assertSame(['pique-2'], $engine->playableCodes($state));
        self::assertSame(3, SolitaireState::fromArray($state->toArray())->draw);
    }

    public function testThePlayedCardsLeaveTheFanUntilOnlyOnePileShows(): void
    {
        $engine = new SolitaireEngine();
        $state = $this->board(
            // Assez de cartes au talon pour qu'il ne s'agisse pas de ses dernières cartes (toutes visibles)
            stock: ['carreau-4', 'carreau-5', 'pique-9', 'pique-8', 'coeur-dame', 'trefle-valet', 'coeur-10'],
            tableau: [[['pique-roi', true]], [['coeur-roi', true]], [['trefle-roi', true]], [], [], [], []],
        );
        $state->draw = 3;
        $state->score = 5000;

        $engine->draw($state);
        self::assertSame(['coeur-10', 'trefle-valet', 'coeur-dame'], $state->visibleWaste());

        // La Dame part sur le Roi de pique : il en reste 2 étalées
        $engine->move($state, 'coeur-dame', 'colonne-0');
        self::assertSame(['coeur-10', 'trefle-valet'], $state->visibleWaste());

        // Le retour remet l'étalement comme avant
        $engine->undo($state);
        self::assertSame(['coeur-10', 'trefle-valet', 'coeur-dame'], $state->visibleWaste());

        $engine->move($state, 'coeur-dame', 'colonne-0');
        $engine->move($state, 'trefle-valet', 'colonne-0');
        self::assertSame(['coeur-10'], $state->visibleWaste());
        $engine->move($state, 'coeur-10', 'colonne-0');
        self::assertSame([], $state->visibleWaste());

        // Le clic suivant : les 2 dernières du talon, et la carte retournée juste avant complète l'éventail
        $state->stock = ['pique-9', 'pique-8'];
        $state->waste = ['coeur-7'];
        $engine->draw($state);
        self::assertSame(['coeur-7', 'pique-8', 'pique-9'], $state->visibleWaste());
        $state->waste = ['coeur-6', 'coeur-7', 'pique-8', 'pique-9'];
        $state->fan = 0;
        self::assertSame(['pique-9'], $state->visibleWaste(), 'Toutes jouées : une seule pile');
        self::assertSame(0, SolitaireState::fromArray($state->toArray())->fan);
    }

    public function testTheLastCardsOfTheStockStayVisibleAndTheStockStopsBeingClicked(): void
    {
        $engine = new SolitaireEngine();
        $state = $this->board(
            stock: ['pique-9', 'pique-8', 'coeur-dame', 'trefle-valet', 'coeur-10'],
            tableau: [[['pique-roi', true]], [['coeur-roi', true]], [['trefle-roi', true]], [['carreau-10', true]], [], [], []],
        );
        $state->draw = 3;

        $engine->draw($state);
        self::assertFalse($state->lastCards(), '5 cartes au talon : on pioche encore');

        // La Dame part : il ne reste que 4 cartes (2 cachées, 2 retournées) ; le Valet part : il n'en reste que 3,
        // les 2 cachées se retournent et les 3 sont visibles
        $engine->move($state, 'coeur-dame', 'colonne-0');
        self::assertFalse($state->lastCards());
        $engine->move($state, 'trefle-valet', 'colonne-0');
        self::assertSame([], $state->stock);
        self::assertSame(['coeur-10', 'pique-8', 'pique-9'], $state->visibleWaste());
        self::assertTrue($state->lastCards());

        // Le talon ne se clique plus
        try {
            $engine->draw($state);
            self::fail('Le talon ne doit plus se retourner.');
        } catch (SolitaireException $exception) {
            self::assertStringContainsString('Plus rien à piocher', $exception->getMessage());
        }

        // Une carte jouée : les 2 qui restent sont toujours visibles
        $engine->move($state, 'pique-9', 'colonne-3');
        self::assertSame(['coeur-10', 'pique-8'], $state->visibleWaste());
        self::assertTrue($state->lastCards());
    }

    public function testTheTwoSolitairesAndTheAutoPlayAtTheDeal(): void
    {
        $classic = (new SolitaireRules(3))->deal(11);
        $harmony = (new SolitaireRules(1))->deal(11);

        self::assertSame(3, $classic->draw);
        self::assertSame(1, $harmony->draw);

        // Après la donne, plus aucun As ni 2 visible ne peut monter : ils sont déjà sur leur famille
        foreach ($classic->tableau as $column) {
            $top = [] === $column ? null : $column[\count($column) - 1];

            if (null !== $top && $top[1]) {
                $card = SolitaireCard::fromCode($top[0]);
                self::assertFalse($card->rank <= 2 && $classic->foundations[$card->suit] === $card->rank - 1, $top[0]);
            }
        }

        // Les cartes montées à la donne sont notées, avec leur pile de départ : la page les montre monter à l'arrivée
        self::assertSame(array_sum($classic->foundations), \count($classic->auto));
        foreach ($classic->auto as [$code, $from]) {
            self::assertMatchesRegularExpression('/^colonne-[0-6]$/', $from, $code);
        }

        // La même donne pour les deux jeux, et le rangement ne s'annule pas avec « retour »
        self::assertSame($classic->foundations, $harmony->foundations);
        self::assertSame([], $classic->history);
        self::assertSame(0, $classic->moves);
    }

    public function testCompleteGameAndTimeBonus(): void
    {
        $engine = new SolitaireEngine();
        $state = $this->board(waste: ['trefle-roi']);
        $state->foundations = ['pique' => 13, 'coeur' => 13, 'carreau' => 13, 'trefle' => 12];

        $engine->toFoundation($state, 'trefle-roi');

        self::assertTrue($state->finished);
        self::assertSame(SolitaireScore::FOUNDATION + SolitaireScore::COMPLETE, $state->score);
        self::assertSame(120 * 150, $engine->finish($state, 120, true));
    }

    public function testStoppingEarlyGivesOnePointPerSecondLeft(): void
    {
        $engine = new SolitaireEngine();
        $state = $engine->deal(3);
        $before = $state->score;

        self::assertSame(120, $engine->finish($state, 120, false));
        self::assertSame($before + 120, $state->score);
        self::assertSame(120, $state->bonus);

        $this->expectException(SolitaireException::class);
        $engine->draw($state);
    }

    public function testStateSurvivesTheSaving(): void
    {
        $engine = new SolitaireEngine();
        $state = $engine->deal(9);
        $engine->draw($state);

        $copy = SolitaireState::fromArray(json_decode(json_encode($state->toArray(), \JSON_THROW_ON_ERROR), true));

        self::assertSame($state->toArray(), $copy->toArray());
    }

    public function testTargetsListWhereEachCardCanGo(): void
    {
        $engine = new SolitaireEngine();
        $state = $this->board(
            waste: ['coeur-as'],
            tableau: [[['pique-10', true]], [['coeur-9', true]], [['trefle-roi', false], ['carreau-9', true]], [], [], [], [['pique-roi', true]]],
        );
        $state->foundations['pique'] = 1;

        $targets = $engine->targets($state);

        self::assertSame(['famille'], $targets['coeur-as']);
        self::assertSame(['colonne-0'], $targets['coeur-9']);
        self::assertSame(['colonne-0'], $targets['carreau-9']);
        // Un Roi va sur une pile vide ; la carte du dessus d'une famille peut revenir sur une pile
        self::assertSame(['colonne-3', 'colonne-4', 'colonne-5'], $targets['pique-roi']);
        self::assertArrayNotHasKey('pique-10', $targets);
        self::assertArrayNotHasKey('pique-as', $targets);

        // Chaque destination annoncée est acceptée par move()
        foreach ($targets as $code => $places) {
            foreach ($places as $place) {
                $copy = SolitaireState::fromArray($state->toArray());
                $engine->move($copy, $code, $place);
            }
        }

        $state->finished = true;
        self::assertSame([], $engine->targets($state));
    }

    public function testTheGameCanFinishAloneOnlyWhenEverythingIsVisible(): void
    {
        $engine = new SolitaireEngine();
        $state = $this->board(tableau: [[['pique-2', true], ['coeur-as', true]], [['pique-as', true]], [], [], [], [], []]);
        self::assertTrue($engine->canFinishAlone($state));

        $state->tableau[0][0][1] = false;
        self::assertFalse($engine->canFinishAlone($state));

        $state->tableau[0][0][1] = true;
        $state->stock = ['trefle-as'];
        self::assertFalse($engine->canFinishAlone($state));
    }

    /**
     * @param list<string>                    $stock
     * @param list<string>                    $waste
     * @param list<list<array{string, bool}>> $tableau
     */
    private function board(array $stock = [], array $waste = [], array $tableau = [[], [], [], [], [], [], []]): SolitaireState
    {
        $state = new SolitaireState(1);
        $state->stock = $stock;
        $state->waste = $waste;
        $state->tableau = $tableau;

        return $state;
    }
}
