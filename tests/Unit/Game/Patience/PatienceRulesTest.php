<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Patience;

use App\Enum\Game;
use App\Game\Patience\PatienceRules;
use App\Game\Patience\PatienceRulesFactory;
use App\Game\Solitaire\SolitaireRules;
use App\Game\Spider\SpiderLevel;
use App\Game\Spider\SpiderRules;
use PHPUnit\Framework\TestCase;

/**
 * Le contrat commun aux jeux de patience : chaque jeu donne, relit, fait jouer un robot et termine une grille de la même façon.
 */
final class PatienceRulesTest extends TestCase
{
    /**
     * @return array<string, array{PatienceRules, int}>
     */
    public static function games(): array
    {
        return [
            'solitaire' => [new SolitaireRules(), 52],
            'spider débutant' => [new SpiderRules(SpiderLevel::Beginner), 104],
            'spider' => [new SpiderRules(SpiderLevel::Classic), 104],
            'spider pro' => [new SpiderRules(SpiderLevel::Pro), 104],
        ];
    }

    public function testEveryGameFollowsTheContract(): void
    {
        foreach (self::games() as $name => [$rules, $cards]) {
            $state = $rules->deal(9);

            self::assertSame($cards, $state->cardCount(), $name);
            self::assertSame(0, $state->sortedCards(), $name);
            self::assertSame(0, $state->score(), $name);
            self::assertFalse($state->isFinished(), $name);
            // La même graine donne la même grille, et une grille se relit telle quelle
            self::assertSame($state->toArray(), $rules->deal(9)->toArray(), $name);
            self::assertSame($state->toArray(), $rules->restore(json_decode(json_encode($state->toArray(), \JSON_THROW_ON_ERROR), true))->toArray(), $name);

            // Arrêter sans tout ranger : la grille est close
            $stopped = $rules->deal(9);
            $rules->finish($stopped, 0, false);
            self::assertTrue($stopped->isFinished(), $name);
            self::assertFalse($stopped->isComplete(), $name);

            // Un robot joue sa grille jusqu'au bout
            $played = $rules->deal(9);
            $rules->botPlay($played);
            self::assertTrue($played->isFinished(), $name);
        }
    }

    public function testTheFactoryKnowsTheSolitaire(): void
    {
        self::assertInstanceOf(SolitaireRules::class, PatienceRulesFactory::for(Game::Solitaire));

        $this->expectException(\LogicException::class);
        PatienceRulesFactory::for(Game::Belote);
    }
}
