<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\CardSet;
use App\Enum\Game;
use App\Game\Patience\PatienceRulesFactory;
use App\Game\Solitaire\SolitaireRules;
use App\Game\Spider\SpiderLevel;
use App\Game\Spider\SpiderRules;
use PHPUnit\Framework\TestCase;

/**
 * Ce qui change d'un jeu à l'autre : joueurs, fin de partie, annonces, durées, jeu de cartes.
 */
final class GameTest extends TestCase
{
    public function testSolitaireIsPlayedByTwoToFivePlayersInOneRound(): void
    {
        $game = Game::Solitaire;

        self::assertSame('Solitaire', $game->label());
        self::assertSame(2, $game->playerCount());
        self::assertSame([2, 3, 4, 5], $game->playerCountChoices());
        self::assertSame([1], $game->targetPointsChoices());
        self::assertSame(1, $game->defaultTargetPoints());
        self::assertSame('manches', $game->targetUnit());
        self::assertNull($game->variant());
        self::assertFalse($game->hasTeams());
        self::assertFalse($game->hasAnnouncements());
        self::assertFalse($game->hasTurns());
        self::assertSame(CardSet::Modern, CardSet::defaultFor($game));
        self::assertSame('1 manche', $game->targetLabel(1));
        self::assertSame('3 manches', $game->targetLabel(3));
        self::assertSame('1 manche', $game->targetLongLabel(1));

        // Deux solitaires : 3 cartes au talon, ou une seule (harmonie)
        self::assertSame(3, $game->solitaireDraw());
        self::assertSame(1, Game::SolitaireHarmonie->solitaireDraw());
        self::assertSame('Solitaire harmonie', Game::SolitaireHarmonie->label());
        self::assertTrue(Game::SolitaireHarmonie->isSolitaire());
        self::assertTrue(Game::SolitaireHarmonie->isPatience());
        self::assertNull(Game::Rami->solitaireDraw());
    }

    public function testTheThreeSpiderArePatienceGamesWithTheirLevel(): void
    {
        $levels = ['spider-debutant' => SpiderLevel::Beginner, 'spider' => SpiderLevel::Classic, 'spider-pro' => SpiderLevel::Pro];

        foreach ($levels as $value => $level) {
            $game = Game::from($value);

            self::assertTrue($game->isSpider());
            self::assertTrue($game->isPatience());
            self::assertFalse($game->isSolitaire());
            self::assertSame($level, $game->spiderLevel());
            self::assertSame($level->label(), $game->label());
            self::assertSame([2, 3, 4, 5], $game->playerCountChoices());
            self::assertSame([1], $game->targetPointsChoices());
            self::assertFalse($game->hasTurns());
            self::assertFalse($game->hasTeams());
            self::assertInstanceOf(SpiderRules::class, PatienceRulesFactory::for($game));
        }

        self::assertSame('Spider débutant', Game::SpiderBeginner->label());
        self::assertNull(Game::Solitaire->spiderLevel());
        self::assertInstanceOf(SolitaireRules::class, PatienceRulesFactory::for(Game::Solitaire));
    }

    public function testRamiIsPlayedByTwoToSixPlayersInPenaltyPoints(): void
    {
        $game = Game::Rami;

        self::assertSame('Rami', $game->label());
        self::assertTrue($game->isRami());
        self::assertSame([2, 3, 4, 5, 6], $game->playerCountChoices());
        self::assertSame([300, 500, 1000], $game->targetPointsChoices());
        self::assertSame(500, $game->defaultTargetPoints());
        self::assertSame('500 pts', $game->targetLabel(500));
        self::assertTrue($game->hasTurns());
        self::assertFalse($game->hasBidding());
        self::assertFalse($game->hasTeams());
        self::assertFalse($game->hasAnnouncements());
        self::assertFalse($game->isPatience());
        self::assertTrue(Game::Tarot->hasBidding());
        self::assertTrue(Game::Belote->hasBidding());
    }

    public function testHuitIsPlayedByTwoToSixPlayersInPenaltyPoints(): void
    {
        $game = Game::HuitAmericain;

        self::assertSame('8 américain', $game->label());
        self::assertTrue($game->isHuit());
        self::assertFalse($game->isRami());
        self::assertTrue($game->hasPenaltyRounds());
        self::assertTrue(Game::Rami->hasPenaltyRounds());
        self::assertFalse(Game::Belote->hasPenaltyRounds());
        self::assertSame([2, 3, 4, 5, 6], $game->playerCountChoices());
        self::assertSame([300, 500, 1000], $game->targetPointsChoices());
        self::assertSame(300, $game->defaultTargetPoints());
        self::assertTrue($game->hasTurns());
        self::assertFalse($game->hasBidding());
        self::assertFalse($game->isPatience());
    }

    public function testGolfIsPlayedByTwoToSixPlayersInNineOrEighteenHoles(): void
    {
        $game = Game::Golf;

        self::assertSame('Golf', $game->label());
        self::assertTrue($game->isGolf());
        self::assertTrue($game->playsInRounds());
        self::assertFalse($game->hasPenaltyRounds());
        self::assertTrue(Game::Rami->playsInRounds());
        self::assertFalse(Game::Tarot->playsInRounds());
        self::assertSame([2, 3, 4, 5, 6], $game->playerCountChoices());
        self::assertSame([9, 18], $game->targetPointsChoices());
        self::assertSame(9, $game->defaultTargetPoints());
        self::assertSame('9 trous', $game->targetLabel(9));
        self::assertSame('18 trous', $game->targetLongLabel(18));
        self::assertTrue($game->hasTurns());
        self::assertFalse($game->hasBidding());
        self::assertFalse($game->isPatience());
    }

    public function testTargetLabels(): void
    {
        self::assertSame('501 pts', Game::Belote->targetLabel(501));
        self::assertSame('1001 points', Game::Coinche->targetLongLabel(1001));
        self::assertSame('4 donnes', Game::Tarot->targetLabel(4));
        self::assertSame('8 donnes', Game::Tarot->targetLongLabel(8));
    }

    public function testEveryGameStillHasItsRules(): void
    {
        self::assertSame([3, 4, 5], Game::Tarot->playerCountChoices());
        self::assertSame('donnes', Game::Tarot->targetUnit());
        self::assertFalse(Game::Tarot->hasAnnouncements());
        self::assertTrue(Game::Tarot->hasTurns());

        foreach ([Game::Belote, Game::Coinche, Game::Contree] as $game) {
            self::assertSame([4], $game->playerCountChoices());
            self::assertTrue($game->hasTeams());
            self::assertTrue($game->hasAnnouncements());
            self::assertSame('points', $game->targetUnit());
        }

        foreach (Game::cases() as $game) {
            self::assertNotSame('', $game->summary());
            self::assertContains($game->defaultTargetPoints(), $game->targetPointsChoices());
        }
    }

    public function testPresident(): void
    {
        self::assertSame('Président', Game::President->label());
        self::assertSame([3, 4, 5, 6, 7], Game::President->playerCountChoices());
        self::assertTrue(Game::President->playsInRounds());
        self::assertFalse(Game::President->hasPenaltyRounds());
        self::assertFalse(Game::President->hasTeams());
        self::assertTrue(Game::President->hasTurns());
        self::assertSame([2, 3, 4], Game::President->targetPointsChoices());
        self::assertSame(3, Game::President->defaultTargetPoints());
        self::assertSame('3 pts/joueur', Game::President->targetLabel(3));
        self::assertSame('2 points par joueur', Game::President->targetLongLabel(2));
    }
}
