<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\GameRoom;
use App\Entity\GameSetting;
use App\Enum\CardBack;
use App\Enum\CardSet;
use App\Enum\Carpet;
use App\Enum\Game;
use PHPUnit\Framework\TestCase;

final class GameSettingTest extends TestCase
{
    public function testDefaults(): void
    {
        $setting = new GameSetting(Game::Belote);

        self::assertSame(Carpet::Lagon, $setting->getCarpet());
        self::assertSame(CardSet::Modern, $setting->getCardSet());
        self::assertSame(CardBack::Modern, $setting->getCardBack());
        self::assertSame(GameRoom::TURN_SECONDS, $setting->getTurnSeconds());
        self::assertSame(GameRoom::BID_SECONDS, $setting->getBidSeconds());
    }

    public function testUpdate(): void
    {
        $setting = new GameSetting(Game::Belote);
        $setting->update(Carpet::Night, CardSet::Modern, 30, 10, CardBack::Modern);

        self::assertSame(Carpet::Night, $setting->getCarpet());
        self::assertSame(CardBack::Modern, $setting->getCardBack());
        self::assertSame(30, $setting->getTurnSeconds());
        self::assertSame(10, $setting->getBidSeconds());
    }

    public function testUnknownDurationIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new GameSetting(Game::Belote))->update(Carpet::Lagon, CardSet::Modern, 12, 15);
    }

    public function testUnknownBidDurationIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new GameSetting(Game::Belote))->update(Carpet::Lagon, CardSet::Modern, 15, 7);
    }

    public function testAGameIsActiveUntilTheAdminSwitchesItOff(): void
    {
        $setting = new GameSetting(Game::Golf);
        self::assertTrue($setting->isActive());

        $setting->setActive(false);
        self::assertFalse($setting->isActive());

        $setting->setActive(true);
        self::assertTrue($setting->isActive());
    }

    public function testCardSetPathPointsToItsImages(): void
    {
        self::assertSame('images/cartes/francais/moderne/', CardSet::Modern->path());
    }
}
