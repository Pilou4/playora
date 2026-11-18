<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Belote;

use App\Game\Belote\Announcement;
use App\Game\Belote\Announcements;
use App\Game\Belote\BeloteBot;
use App\Game\Belote\BeloteEngine;
use App\Game\Belote\BeloteException;
use App\Game\Belote\BeloteState;
use App\Game\Belote\Phase;
use App\Game\Belote\Suit;
use App\Game\Belote\Variant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Annonces (règles GameDuell) : tierce 20, cinquante 50, cent 100, carrés (Valets 200, 9 150, As, 10, Rois, Dames 100).
 */
final class AnnouncementsTest extends TestCase
{
    use CardsTrait;

    public function testSuitesFollowAceKingQueenJackTenNineEightSeven(): void
    {
        $hand = self::cs('coeur-7', 'coeur-8', 'coeur-9', 'pique-10', 'pique-valet', 'pique-dame', 'pique-roi', 'trefle-as');

        self::assertSame(['Cinquante', 'Tierce'], $this->names(Announcements::inHand($hand)));
        self::assertSame([50, 20], array_map(static fn (Announcement $a): int => $a->points, Announcements::inHand($hand)));
    }

    public function testFiveCardsOrMoreMakeACent(): void
    {
        $hand = self::cs('carreau-9', 'carreau-10', 'carreau-valet', 'carreau-dame', 'carreau-roi', 'carreau-as', 'pique-7', 'trefle-8');

        self::assertSame(['Cent'], $this->names(Announcements::inHand($hand)));
    }

    public function testSquaresButNotOfEightsOrSevens(): void
    {
        $jacks = self::cs('coeur-valet', 'pique-valet', 'carreau-valet', 'trefle-valet', 'coeur-8', 'pique-8', 'carreau-8', 'trefle-8');
        $nines = self::cs('coeur-9', 'pique-9', 'carreau-9', 'trefle-9', 'coeur-dame', 'pique-dame', 'carreau-dame', 'trefle-dame');

        self::assertSame(['Carré de Valets'], $this->names(Announcements::inHand($jacks)));
        self::assertSame(['Carré de 9', 'Carré de Dames'], $this->names(Announcements::inHand($nines)));
        self::assertSame(250, array_sum(array_map(static fn (Announcement $a): int => $a->points, Announcements::inHand($nines))));
    }

    public function testACardServesOnlyOnceAndTheBestCombinationIsKept(): void
    {
        // Carré de Valets (200) ou tierce 10-Valet-Dame (20) : le Valet de cœur ne sert qu'une fois
        $hand = self::cs('coeur-valet', 'pique-valet', 'carreau-valet', 'trefle-valet', 'coeur-10', 'coeur-dame', 'pique-7', 'trefle-8');

        self::assertSame(['Carré de Valets'], $this->names(Announcements::inHand($hand)));
    }

    public function testNoAnnouncement(): void
    {
        self::assertSame([], Announcements::inHand(self::cs('coeur-7', 'coeur-9', 'pique-7', 'pique-dame', 'carreau-8', 'carreau-roi', 'trefle-10', 'trefle-as')));
    }

    public function testTheBestAnnouncementWinsAndTrumpBreaksTies(): void
    {
        $tierce = static fn (string $suit): Announcement => new Announcement(20, self::cs("$suit-7", "$suit-8", "$suit-9"), Suit::from($suit));
        $fifty = new Announcement(50, self::cs('pique-7', 'pique-8', 'pique-9', 'pique-10'), Suit::Spades);

        // Le plus de points gagne
        self::assertSame(1, Announcements::winningTeam([0 => [$tierce('coeur')], 1 => [$fifty]], Suit::Hearts, 3, false));
        // À égalité, l'annonce à l'atout gagne
        self::assertSame(1, Announcements::winningTeam([0 => [$tierce('pique')], 1 => [$tierce('coeur')]], Suit::Hearts, 3, false));
    }

    public function testTieWithoutTrump(): void
    {
        $declarations = [0 => [new Announcement(20, self::cs('pique-7', 'pique-8', 'pique-9'), Suit::Spades)], 1 => [new Announcement(20, self::cs('trefle-7', 'trefle-8', 'trefle-9'), Suit::Clubs)]];

        // Belote : le plus proche du donneur (donneur 0 : la place 1 parle d'abord) ; coinche : les annonces s'annulent
        self::assertSame(1, Announcements::winningTeam($declarations, Suit::Hearts, 0, false));
        self::assertNull(Announcements::winningTeam($declarations, Suit::Hearts, 0, true));
    }

    public function testDeclaringOnlyBeforeYourFirstCardAndOnlyWithAnnouncements(): void
    {
        $engine = new BeloteEngine(new Randomizer(new Xoshiro256StarStar(7)));
        [$state] = $engine->start(501, 0, Variant::Coinche, announcements: true);
        $state->phase = Phase::Playing;
        $state->trump = Suit::Hearts;
        $state->turn = 1;
        $state->hands[1] = self::cs('coeur-7', 'coeur-8', 'coeur-9', 'pique-7', 'pique-9', 'carreau-7', 'carreau-9', 'trefle-7');

        $result = $engine->declare($state, 1);

        self::assertSame([['type' => 'declared', 'position' => 1, 'names' => ['Tierce']]], $result->events);
        self::assertSame([], $engine->declarable($state, 1));

        $this->expectException(BeloteException::class);
        $engine->declare($state, 1);
    }

    public function testNoAnnouncementsInARoomWithout(): void
    {
        $engine = new BeloteEngine(new Randomizer(new Xoshiro256StarStar(7)));
        [$state] = $engine->start(501, 0, Variant::Coinche);
        $state->phase = Phase::Playing;
        $state->turn = 1;
        $state->hands[1] = self::cs('coeur-7', 'coeur-8', 'coeur-9', 'pique-7', 'pique-9', 'carreau-7', 'carreau-9', 'trefle-7');

        self::assertSame([], $engine->declarable($state, 1));
    }

    public function testDeclarationsSurviveTheSaving(): void
    {
        $state = new BeloteState(501, 0, Variant::Classic, true);
        $state->declarations = [2 => [new Announcement(50, self::cs('pique-7', 'pique-8', 'pique-9', 'pique-10'), Suit::Spades)]];
        $state->announceTeam = 0;
        $state->announcePoints = 50;

        $copy = BeloteState::fromArray(json_decode(json_encode($state->toArray(), \JSON_THROW_ON_ERROR), true));

        self::assertTrue($copy->announcements);
        self::assertSame('Cinquante', $copy->declarations[2][0]->name());
        self::assertSame(Suit::Spades, $copy->declarations[2][0]->suit);
        self::assertSame(50, $copy->announcePoints);
    }

    /**
     * @return iterable<string, array{Variant, int}>
     */
    public static function games(): iterable
    {
        foreach ([1, 2] as $seed) {
            yield 'belote '.$seed => [Variant::Classic, $seed];
            yield 'coinche '.$seed => [Variant::Coinche, $seed];
        }
    }

    #[DataProvider('games')]
    public function testBotsPlayAWholeGameWithAnnouncements(Variant $variant, int $seed): void
    {
        $engine = new BeloteEngine(new Randomizer(new Xoshiro256StarStar($seed)));
        $bot = new BeloteBot($engine);
        [$state] = $engine->start(501, 0, $variant, true);
        $resolved = 0;

        for ($actions = 0; Phase::Finished !== $state->phase; ++$actions) {
            self::assertLessThan(20000, $actions, 'La partie doit se terminer.');

            if ([] !== $engine->declarable($state, $state->turn)) {
                $engine->declare($state, $state->turn);
            }

            foreach ($bot->act($state)->events as $event) {
                $resolved += 'announcements' === $event['type'] ? 1 : 0;
            }
        }

        self::assertGreaterThan(0, $resolved, 'Des annonces doivent avoir été faites.');
        self::assertNotSame($state->scores[0], $state->scores[1]);
    }

    /**
     * @param list<Announcement> $announcements
     *
     * @return list<string>
     */
    private function names(array $announcements): array
    {
        return array_map(static fn (Announcement $a): string => $a->name(), $announcements);
    }
}
