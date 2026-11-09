<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\BeloteDeal;
use App\Entity\BeloteGame;
use App\Entity\GamePlayer;
use App\Entity\GameRoom;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\Game;
use App\Enum\RoomStatus;
use App\Service\GameRoomManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Parties de belote contre 3 robots (PLAYORA_BOTS=1 dans .env.test).
 */
final class BeloteControllerTest extends WebTestCase
{
    private const string AVATAR = 'portrait.0.0.1.0.2.2.0.0.0.0.0.0.0.1.0.0.0.0.1';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        static::getContainer()->get('cache.rate_limiter')->clear();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testThreeBotsCompleteTheTableAndTheGameStarts(): void
    {
        [$user, $game] = $this->gameAgainstBots();

        self::assertSame(RoomStatus::Playing, $game->getRoom()->getStatus());
        self::assertCount(4, $this->entityManager->getRepository(GamePlayer::class)->findBy(['room' => $game->getRoom()]));
        self::assertCount(3, array_filter($game->getRoom()->getSeats(), static fn ($seat): bool => $seat->isBot()));

        $this->client->loginUser($user);
        $this->client->request('GET', '/jeux/belote/parties/'.$game->getRoom()->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main#partie.belote');
        self::assertSelectorCount(1, 'h1');
    }

    public function testViewShowsOnlyMyOwnCards(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);

        $view = $this->api('GET', $this->url($game))['view'];
        $state = $game->getState()->toArray();

        self::assertSame($state['hands'][$view['me']], $view['hand']);
        self::assertSame(array_map('count', $state['hands']), $view['counts']);
        self::assertArrayNotHasKey('hands', $view);
        self::assertCount(4, $view['players']);
    }

    public function testWholeGameIsPlayedAndRecorded(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);
        $view = $this->api('GET', $this->url($game))['view'];

        for ($actions = 0; 'terminee' !== $view['phase']; ++$actions) {
            self::assertLessThan(400, $actions, 'La partie doit se terminer.');
            self::assertSame($view['me'], $view['turn'], 'Les robots jouent jusqu’à mon tour.');

            if (0 === $actions % 40) {
                static::getContainer()->get('cache.rate_limiter')->clear();
            }

            $body = 'jeu' === $view['phase']
                ? $this->api('POST', $this->url($game).'/cartes', ['card' => $view['playable'][0]])
                : $this->api('POST', $this->url($game).'/encheres', ['take' => 'encheres-1' === $view['phase']]);

            self::assertResponseIsSuccessful();
            self::assertNotEmpty($body['events']);
            $view = $body['view'];
        }

        // Le tableau des scores : une ligne par donne, dont la somme donne le score final
        self::assertSame($view['scores'], [array_sum(array_column($view['dealScores'], 0)), array_sum(array_column($view['dealScores'], 1))]);
        self::assertSame(162, array_sum($view['points']), 'Les 162 points de la dernière donne.');

        $this->entityManager->clear();
        $saved = $this->entityManager->find(BeloteGame::class, $game->getId());
        $players = $this->entityManager->getRepository(GamePlayer::class)->findBy(['room' => $saved?->getRoom()]);

        self::assertNotNull($saved?->getWinnerTeam());
        self::assertSame(RoomStatus::Closed, $saved->getRoom()->getStatus());
        self::assertNotNull($saved->getRoom()->getFinishedAt());
        self::assertNotEmpty($this->entityManager->getRepository(BeloteDeal::class)->findBy(['game' => $saved]));
        self::assertSame(8 * \count($this->entityManager->getRepository(BeloteDeal::class)->findBy(['game' => $saved])), array_sum(array_map(static fn (GamePlayer $player): int => $player->getTricksWon(), $players)));
        self::assertCount(2, array_filter($players, static fn (GamePlayer $player): bool => true === $player->hasWon()));
    }

    public function testServerPlaysForThePlayerWhenTimeIsUp(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);
        $before = $this->api('GET', $this->url($game))['view'];
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE belote_game SET turn_started_at = :old WHERE id = :id',
            ['old' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), 'id' => $game->getId()],
        );

        $body = $this->api('POST', $this->url($game).'/temps-ecoule');

        self::assertResponseIsSuccessful();
        self::assertNotEmpty($body['events']);
        self::assertGreaterThan($before['version'], $body['view']['version']);
        self::assertSame($body['view']['me'], $body['view']['turn'], 'Les robots ont rejoué jusqu’à mon tour.');
        self::assertGreaterThan(14, $body['view']['secondsLeft']);
    }

    public function testTimeoutIsRefusedWhileTheTimeIsNotUp(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);

        $this->api('POST', $this->url($game).'/temps-ecoule');

        self::assertResponseStatusCodeSame(409);
    }

    public function testRefusedMoveExplainsWhyAndReturnsTheView(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);
        $view = $this->api('GET', $this->url($game))['view'];

        // Pendant les enchères, on ne joue pas de carte
        $body = $this->api('POST', $this->url($game).'/cartes', ['card' => $view['hand'][0]]);

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertStringContainsString('enchères', $body['detail']);
        self::assertSame($view['version'], $body['view']['version']);
    }

    public function testUnknownCardIsRefused(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);

        $this->api('POST', $this->url($game).'/cartes', ['card' => 'coeur-joker']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testOnlyPlayersAtTheTableSeeTheGame(): void
    {
        [, $game] = $this->gameAgainstBots();
        $this->client->loginUser($this->createUser('Curieux'));

        $this->api('GET', $this->url($game));

        self::assertResponseStatusCodeSame(403);
    }

    public function testMoveFromAnotherSiteIsRefused(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);

        $this->client->request('POST', $this->url($game).'/encheres', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
            'HTTP_ORIGIN' => 'https://pirate.example',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ], json_encode(['take' => false], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(403);
    }

    public function testRoomWithOnlyBotsLeftIsClosed(): void
    {
        $user = $this->createUser('Fred');
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($user, Game::Belote, 501, false);
        $manager->addBot($user, $room, 2);

        $manager->leave($user, $room);

        self::assertSame(RoomStatus::Closed, $room->getStatus());
    }

    /**
     * @return array{User, BeloteGame}
     */
    private function gameAgainstBots(): array
    {
        $user = $this->createUser('Fred');
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($user, Game::Belote, 501, false);

        foreach ([2, 3, 4] as $position) {
            $manager->addBot($user, $room, $position);
        }

        $game = $this->entityManager->getRepository(BeloteGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');

        return [$user, $game];
    }

    private function url(BeloteGame $game): string
    {
        return '/api/v1/jeux/belote/parties/'.$game->getId();
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $url, ?array $body = null): array
    {
        $this->client->request($method, $url, [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function createUser(string $pseudo): User
    {
        $user = new User(bin2hex(random_bytes(6)).'@exemple.fr', $pseudo);
        $user->setPassword('pas-utilise-dans-ces-tests');
        $user->markEmailAsVerified();
        $profile = new Profile($user);
        $profile->setAvatar(self::AVATAR);
        $this->entityManager->persist($user);
        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $user;
    }

    private function purge(): void
    {
        $connection = $this->entityManager->getConnection();

        foreach (['belote_deal', 'belote_game', 'game_player', 'game_room_seat', 'game_room', 'reset_password_request', 'profile', '`user`'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
    }
}
