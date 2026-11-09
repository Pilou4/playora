<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\GameRoom;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\Game;
use App\Enum\RoomStatus;
use App\Service\GameRoomManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameRoomControllerTest extends WebTestCase
{
    private const string AVATAR = 'portrait.0.0.1.0.2.2.0.0.0.0.0.0.0.1.0.0.0.0.1';

    private const string API = '/api/v1/jeux/belote/salons';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Jeton CSRF « sans session » : Symfony vérifie que la requête vient bien du site
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $container = static::getContainer();
        $container->get('cache.rate_limiter')->clear();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testListIsPublic(): void
    {
        $this->manager()->create($this->createUser('Fred'), Game::Belote, 1001, true);

        $state = $this->api('GET', self::API);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertCount(1, $state['rooms']);
        self::assertSame([1001, 1, true], [$state['rooms'][0]['targetPoints'], $state['rooms'][0]['rounds'], $state['rooms'][0]['announcements']]);
        self::assertNull($state['mine']);
    }

    public function testCreateNeedsAnAccount(): void
    {
        $this->api('POST', self::API, ['targetPoints' => 1001, 'announcements' => true]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testPlayerCreatesARoom(): void
    {
        $this->client->loginUser($this->createUser('Fred'));

        $state = $this->api('POST', self::API, ['targetPoints' => 501, 'announcements' => false]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(501, $state['mine']['targetPoints']);
        self::assertFalse($state['mine']['announcements']);
        self::assertSame('waiting', $state['mine']['status']);
        self::assertSame(['pseudo' => 'Fred', 'color' => $state['mine']['seats'][0]['color'], 'avatar' => '/avatars/'.self::AVATAR.'-visage.svg?v=5', 'me' => true, 'bot' => false], $state['mine']['seats'][0]);
        self::assertSame([], $state['rooms']);
    }

    public function testNewRoomIsWithoutAnnouncementsByDefault(): void
    {
        $this->client->loginUser($this->createUser('Fred'));

        $state = $this->api('POST', self::API, ['targetPoints' => 1001]);

        self::assertResponseStatusCodeSame(201);
        self::assertFalse($state['mine']['announcements']);
    }

    public function testForbiddenPointsAreRefused(): void
    {
        $this->client->loginUser($this->createUser('Fred'));

        $this->api('POST', self::API, ['targetPoints' => 3000]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRequestFromAnotherSiteIsRefused(): void
    {
        $this->client->loginUser($this->createUser('Fred'));
        $this->client->setServerParameter('HTTP_SEC_FETCH_SITE', 'cross-site');

        $this->api('POST', self::API, ['targetPoints' => 1001]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->rooms());
    }

    public function testOnlyOneRoomAtATime(): void
    {
        $fred = $this->createUser('Fred');
        $this->manager()->create($fred, Game::Belote, 1001, true);
        $other = $this->manager()->create($this->createUser('Léo'), Game::Belote, 1001, true);
        $this->client->loginUser($fred);

        $problem = $this->api('POST', self::API.'/'.$other->getId().'/places/2');

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertStringContainsString('déjà dans un salon', $problem['detail']);
        self::assertArrayHasKey('state', $problem);
    }

    public function testPlayerTakesTheChosenSeatAndClickingTwiceChangesNothing(): void
    {
        $room = $this->manager()->create($this->createUser('Fred'), Game::Belote, 1001, true);
        $this->client->loginUser($this->createUser('Léo'));

        $this->api('POST', self::API.'/'.$room->getId().'/places/3');
        $state = $this->api('POST', self::API.'/'.$room->getId().'/places/4');

        self::assertResponseIsSuccessful();
        self::assertSame(['Fred', null, 'Léo', null], array_map(static fn (?array $seat): ?string => $seat['pseudo'] ?? null, $state['mine']['seats']));
        self::assertTrue($state['mine']['seats'][2]['me']);
    }

    public function testTakenSeatIsRefused(): void
    {
        $room = $this->manager()->create($this->createUser('Fred'), Game::Belote, 1001, true);
        $this->client->loginUser($this->createUser('Léo'));

        $problem = $this->api('POST', self::API.'/'.$room->getId().'/places/1');

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('vient d’être prise', $problem['detail']);
        self::assertNull($problem['state']['mine']);
    }

    public function testUnknownSeatIsRefused(): void
    {
        $room = $this->manager()->create($this->createUser('Fred'), Game::Belote, 1001, true);
        $this->client->loginUser($this->createUser('Léo'));

        $this->api('POST', self::API.'/'.$room->getId().'/places/5');
        self::assertResponseStatusCodeSame(409);

        $this->api('POST', self::API.'/'.$room->getId().'/places/0');
        self::assertResponseStatusCodeSame(404);
    }

    public function testFourthPlayerStartsTheGameWithRandomTeams(): void
    {
        $room = $this->manager()->create($this->createUser('Fred'), Game::Belote, 1001, true);
        $this->manager()->join($this->createUser('Léo'), $room);
        $this->manager()->join($this->createUser('Sarah'), $room);
        $this->client->loginUser($this->createUser('Camille'));

        $state = $this->api('POST', self::API.'/'.$room->getId().'/places/4');

        self::assertSame('playing', $state['mine']['status']);
        self::assertSame('/jeux/belote/parties/'.$room->getId(), $state['mine']['playUrl']);
        $pseudos = array_map(static fn (array $seat): string => $seat['pseudo'], $state['mine']['seats']);
        sort($pseudos);
        self::assertSame(['Camille', 'Fred', 'Léo', 'Sarah'], $pseudos);

        $this->client->request('GET', $state['mine']['playUrl']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main#partie.belote');
    }

    public function testFullRoomRefusesAFifthPlayer(): void
    {
        $room = $this->manager()->create($this->createUser('Fred'), Game::Belote, 1001, true);
        foreach (['Léo', 'Sarah', 'Camille'] as $pseudo) {
            $this->manager()->join($this->createUser($pseudo), $room);
        }
        $this->client->loginUser($this->createUser('Hugo'));

        $problem = $this->api('POST', self::API.'/'.$room->getId().'/places/1');

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('n’accepte plus', $problem['detail']);
    }

    public function testRoomClosesWhenTheLastPlayerLeaves(): void
    {
        $fred = $this->createUser('Fred');
        $room = $this->manager()->create($fred, Game::Belote, 1001, true);
        $this->client->loginUser($fred);

        $state = $this->api('DELETE', self::API.'/'.$room->getId().'/joueurs');

        self::assertResponseIsSuccessful();
        self::assertNull($state['mine']);
        self::assertSame(RoomStatus::Closed, $this->reload($room)->getStatus());
    }

    public function testRoomStaysOpenWhileSomeoneIsSeated(): void
    {
        $fred = $this->createUser('Fred');
        $room = $this->manager()->create($fred, Game::Belote, 1001, true);
        $this->manager()->join($this->createUser('Léo'), $room);
        $this->client->loginUser($fred);

        $this->api('DELETE', self::API.'/'.$room->getId().'/joueurs');

        $room = $this->reload($room);
        self::assertSame(RoomStatus::Waiting, $room->getStatus());
        self::assertSame(1, $room->getOccupiedSeatCount());
    }

    public function testAbsentPlayersLoseTheirSeat(): void
    {
        $room = $this->manager()->create($this->createUser('Fred'), Game::Belote, 1001, true);
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE game_room_seat SET last_seen_at = :old WHERE user_id IS NOT NULL',
            ['old' => (new \DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s')],
        );

        $state = $this->api('GET', self::API);

        self::assertSame([], $state['rooms']);
        self::assertSame(RoomStatus::Closed, $this->reload($room)->getStatus());
    }

    public function testPresenceKeepsTheSeat(): void
    {
        $fred = $this->createUser('Fred');
        $room = $this->manager()->create($fred, Game::Belote, 1001, true);
        $this->client->loginUser($fred);

        $this->client->request('POST', '/api/v1/jeux/belote/presence', [], [], ['HTTP_X_CSRF_TOKEN' => 'csrf-token']);

        self::assertResponseStatusCodeSame(204);
        self::assertGreaterThan(new \DateTimeImmutable('-10 seconds'), $this->reload($room)->getSeat(1)?->getLastSeenAt());
    }

    public function testOnlySeatedPlayersSeeTheGame(): void
    {
        $room = $this->manager()->create($this->createUser('Fred'), Game::Belote, 1001, true);
        $this->client->loginUser($this->createUser('Intrus'));

        $this->client->request('GET', '/jeux/belote/parties/'.$room->getId());

        self::assertResponseStatusCodeSame(403);
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

    private function manager(): GameRoomManager
    {
        return static::getContainer()->get(GameRoomManager::class);
    }

    private function reload(GameRoom $room): GameRoom
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(GameRoom::class, $room->getId()) ?? self::fail('Salon introuvable.');
    }

    /**
     * @return list<GameRoom>
     */
    private function rooms(): array
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(GameRoom::class)->findAll();
    }

    private function createUser(string $pseudo): User
    {
        $user = new User(bin2hex(random_bytes(6)).'@exemple.fr', $pseudo);
        $user->setPassword('pas-utilise-dans-ces-tests');
        $user->markEmailAsVerified();
        // Avec un avatar : sinon les pages du site renvoient vers « Choisissez votre avatar »
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

        foreach (['game_room_seat', 'game_room', 'profile', '`user`'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
    }
}
