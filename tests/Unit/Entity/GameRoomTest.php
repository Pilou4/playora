<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\GameRoom;
use App\Entity\User;
use App\Enum\Game;
use App\Enum\RoomStatus;
use App\Enum\RoomVisibility;
use PHPUnit\Framework\TestCase;

final class GameRoomTest extends TestCase
{
    public function testBeloteRoomHasFourSeatsInTwoTeams(): void
    {
        $room = $this->room();

        self::assertCount(4, $room->getSeats());
        self::assertSame([1, 2, 1, 2], array_map(static fn ($seat): int => $seat->getTeam(), $room->getSeats()));
        self::assertSame(1, $room->getRounds());
        self::assertSame(GameRoom::TURN_SECONDS, $room->getTurnSeconds());
        self::assertTrue($room->isEmpty());
    }

    public function testFullAndEmpty(): void
    {
        $room = $this->room();

        foreach ($room->getSeats() as $i => $seat) {
            $seat->occupy(new User("j$i@exemple.fr", "Joueur$i"));
        }

        self::assertTrue($room->isFull());
        $room->start();
        self::assertSame(RoomStatus::Playing, $room->getStatus());
        self::assertNotNull($room->getStartedAt());
    }

    public function testFirstFreeSeat(): void
    {
        $room = $this->room();
        $room->getSeat(1)?->occupy(new User('a@exemple.fr', 'Alice'));
        $room->getSeat(2)?->occupy(new User('b@exemple.fr', 'Bob'));

        self::assertSame(3, $room->getFirstFreeSeat()?->getPosition());
        self::assertNotNull($room->getSeat(1)?->getLastSeenAt());
    }

    public function testSeatOfAPlayer(): void
    {
        $room = $this->room();
        $fred = new User('fred@exemple.fr', 'Fred');
        $room->getSeat(3)?->occupy($fred);

        self::assertSame(3, $room->getSeatOf($fred)?->getPosition());
        self::assertSame(1, $room->getOccupiedSeatCount());

        $room->getSeat(3)?->release();
        self::assertNull($room->getSeatOf($fred));
    }

    private function room(): GameRoom
    {
        return new GameRoom(Game::Belote, new User('hote@exemple.fr', 'Hote'), 'ABC234', 1001, RoomVisibility::Public, true);
    }
}
