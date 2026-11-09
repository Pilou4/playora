<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Belote;

use App\Game\Belote\BeloteEngine;
use App\Game\Belote\BeloteException;
use App\Game\Belote\BeloteState;
use App\Game\Belote\Phase;
use App\Game\Belote\Suit;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

final class BeloteEngineTest extends TestCase
{
    use CardsTrait;

    private BeloteEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new BeloteEngine(new Randomizer(new Xoshiro256StarStar(42)));
    }

    // ---------- Donne et enchères ----------

    public function testDealGivesFiveCardsAndTurnsOneUp(): void
    {
        [$state, $result] = $this->engine->start(1001, 3);

        self::assertSame(Phase::FirstBidding, $state->phase);
        self::assertSame([5, 5, 5, 5], array_map('count', $state->hands));
        self::assertNotNull($state->turnedCard);
        self::assertCount(11, $state->stock);
        // On commence à gauche du donneur
        self::assertSame(0, $state->turn);
        self::assertSame('dealt', $result->events[0]['type']);
    }

    public function testTakingCompletesEveryHandToEightCards(): void
    {
        [$state] = $this->engine->start(1001, 3);
        $turned = $state->turnedCard;

        $this->engine->take($state, 0);

        self::assertSame(Phase::Playing, $state->phase);
        self::assertSame($turned?->suit, $state->trump);
        self::assertSame(0, $state->taker);
        self::assertSame([8, 8, 8, 8], array_map('count', $state->hands));
        self::assertSame([], $state->stock);
        self::assertTrue(\in_array($turned?->code(), array_map(static fn ($card) => $card->code(), $state->hands[0]), true));
        self::assertSame(0, $state->turn);
    }

    public function testFirstRoundOnlyAcceptsTheTurnedSuit(): void
    {
        [$state] = $this->engine->start(1001, 3);
        $other = $this->otherSuit($state);

        $this->expectException(BeloteException::class);
        $this->expectExceptionMessage('couleur retournée');

        $this->engine->take($state, 0, $other);
    }

    public function testSecondRoundRequiresAnotherSuit(): void
    {
        [$state] = $this->engine->start(1001, 3);

        foreach ([0, 1, 2, 3] as $position) {
            $this->engine->pass($state, $position);
        }

        self::assertSame(Phase::SecondBidding, $state->phase);

        try {
            $this->engine->take($state, 0, $state->turnedCard?->suit);
            self::fail('La couleur retournée doit être refusée au second tour.');
        } catch (BeloteException $exception) {
            self::assertStringContainsString('autre couleur', $exception->getMessage());
        }

        $other = $this->otherSuit($state);
        $this->engine->take($state, 0, $other);
        self::assertSame($other, $state->trump);
    }

    public function testEveryonePassingTwiceRedealsWithTheNextDealer(): void
    {
        [$state] = $this->engine->start(1001, 3);

        for ($i = 0; $i < 8; ++$i) {
            $result = $this->engine->pass($state, $state->turn);
        }

        self::assertSame('redeal', $result->events[1]['type']);
        self::assertSame(0, $state->dealer);
        self::assertSame(2, $state->dealNumber);
        self::assertSame(Phase::FirstBidding, $state->phase);
        self::assertSame(1, $state->turn);
    }

    public function testOnlyThePlayerWhoseTurnItIsCanAct(): void
    {
        [$state] = $this->engine->start(1001, 3);

        $this->expectException(BeloteException::class);
        $this->expectExceptionMessage('pas votre tour');

        $this->engine->pass($state, 2);
    }

    // ---------- Jeu ----------

    public function testRefusedCardExplainsWhy(): void
    {
        $state = $this->playing(['pique-as', 'carreau-7'], ['pique-7', 'trefle-8'], ['coeur-7', 'coeur-8'], ['trefle-7', 'trefle-9']);
        $this->engine->play($state, 0, self::c('pique-as'));

        $this->expectException(BeloteException::class);
        $this->expectExceptionMessage('Vous devez fournir à pique.');

        $this->engine->play($state, 1, self::c('trefle-8'));
    }

    public function testCardNotInHandIsRefused(): void
    {
        $state = $this->playing(['pique-as'], ['pique-7'], ['coeur-7'], ['trefle-7']);

        $this->expectException(BeloteException::class);

        $this->engine->play($state, 0, self::c('carreau-as'));
    }

    public function testTrickWinnerCollectsThePointsAndLeadsNext(): void
    {
        $state = $this->playing(['pique-as', 'pique-8'], ['pique-10', 'trefle-8'], ['coeur-7', 'carreau-8'], ['pique-roi', 'trefle-9']);

        $this->engine->play($state, 0, self::c('pique-as'));
        $this->engine->play($state, 1, self::c('pique-10'));
        // Place 2 n'a pas de pique : partenaire (0) maître, défausse libre
        $this->engine->play($state, 2, self::c('carreau-8'));
        $result = $this->engine->play($state, 3, self::c('pique-roi'));

        $trick = $result->events[1];
        self::assertSame(['type' => 'trick', 'winner' => 0, 'points' => 25, 'last' => false], $trick);
        self::assertSame([25, 0], $state->cardPoints);
        self::assertSame(0, $state->turn);
        self::assertSame([1, 0, 0, 0], $state->playerTricks);
    }

    public function testBeloteAndRebeloteAreAnnounced(): void
    {
        $state = $this->playing(['coeur-roi', 'coeur-dame'], ['coeur-7', 'pique-8'], ['coeur-8', 'pique-9'], ['coeur-9', 'pique-10']);
        $state->beloteHolder = 0;

        $first = $this->engine->play($state, 0, self::c('coeur-roi'));
        self::assertSame('Belote', $first->events[1]['word']);

        $this->engine->play($state, 1, self::c('coeur-7'));
        $this->engine->play($state, 2, self::c('coeur-8'));
        $this->engine->play($state, 3, self::c('coeur-9'));
        // Le 9 d'atout gagne : la place 3 entame
        $this->engine->play($state, 3, self::c('pique-10'));
        $this->engine->play($state, 0, self::c('coeur-dame'));
        self::assertSame(2, $state->belotePlayed);
    }

    public function testLastTrickEndsTheDealAndAddsTheDixDeDer(): void
    {
        $state = $this->playing(['pique-as'], ['pique-7'], ['pique-8'], ['pique-9']);
        $state->cardPoints = [100, 51];

        $result = $this->engine->play($state, 0, self::c('pique-as'));
        $this->engine->play($state, 1, self::c('pique-7'));
        $this->engine->play($state, 2, self::c('pique-8'));
        $result = $this->engine->play($state, 3, self::c('pique-9'));

        self::assertCount(1, $result->deals);
        $deal = $result->deals[0];
        // 100 + 11 + dix de der 10 = 121 ; 51
        self::assertSame([121, 51], $deal->cardPoints);
        self::assertSame([121, 51], $state->scores);
        self::assertSame([[121, 51]], $state->dealScores);
        // Donne suivante distribuée par le joueur suivant (le donneur était la place 3)
        self::assertSame(Phase::FirstBidding, $state->phase);
        self::assertSame(0, $state->dealer);
    }

    public function testGameEndsWhenATeamReachesTheTarget(): void
    {
        $state = $this->playing(['pique-as'], ['pique-7'], ['pique-8'], ['pique-9']);
        $state->scores = [950, 400];
        $state->cardPoints = [100, 51];

        foreach ([[0, 'pique-as'], [1, 'pique-7'], [2, 'pique-8'], [3, 'pique-9']] as [$position, $card]) {
            $result = $this->engine->play($state, $position, self::c($card));
        }

        self::assertSame(Phase::Finished, $state->phase);
        self::assertSame(0, $state->winnerTeam);
        self::assertSame('gameEnd', $result->events[array_key_last($result->events)]['type']);

        $this->expectException(BeloteException::class);
        $this->engine->pass($state, $state->turn);
    }

    public function testStateSurvivesTheJsonRoundTrip(): void
    {
        [$state] = $this->engine->start(1001, 2);
        $this->engine->take($state, $state->turn);
        $this->engine->play($state, $state->turn, $state->playableCards($state->turn)[0]);

        $copy = BeloteState::fromArray(json_decode(json_encode($state->toArray(), \JSON_THROW_ON_ERROR), true));

        self::assertSame($state->toArray(), $copy->toArray());
    }

    /**
     * Partie en cours, atout cœur, preneur 0, donneur 3 : c'est à la place 0 d'entamer.
     *
     * @param list<string> ...$hands
     */
    private function playing(array ...$hands): BeloteState
    {
        $state = new BeloteState(1001, 3);
        $state->phase = Phase::Playing;
        $state->dealNumber = 1;
        $state->taker = 0;
        $state->trump = Suit::Hearts;
        $state->turn = 0;
        $state->hands = array_map(static fn (array $codes): array => self::cs(...$codes), $hands);

        return $state;
    }

    private function otherSuit(BeloteState $state): Suit
    {
        foreach (Suit::cases() as $suit) {
            if ($suit !== $state->turnedCard?->suit) {
                return $suit;
            }
        }

        throw new \LogicException();
    }
}
