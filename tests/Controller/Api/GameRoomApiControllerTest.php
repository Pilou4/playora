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

final class GameRoomApiControllerTest extends WebTestCase
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

        // Valeur impossible : refusée dès la lecture de la requête
        $this->api('POST', self::API, ['targetPoints' => -1]);
        self::assertResponseStatusCodeSame(422);

        // Valeur qui n'existe pas pour ce jeu : refusée par les règles des salons
        $this->api('POST', self::API, ['targetPoints' => 3000]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testPlayerCreatesACoincheRoomInTheCoincheLobby(): void
    {
        $this->client->loginUser($this->createUser('Fred'));

        $state = $this->api('POST', '/api/v1/jeux/coinche/salons', ['targetPoints' => 701]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('coinche', $state['mine']['variant']);
        self::assertSame('Coinche', $state['mine']['variantLabel']);
        self::assertSame(701, $state['mine']['targetPoints']);
        self::assertStringStartsWith('/jeux/coinche/parties/', $state['mine']['playUrl']);
    }

    public function testTarotRoomIsPlayedInDeals(): void
    {
        $this->client->loginUser($this->createUser('Fred'));

        $state = $this->api('POST', '/api/v1/jeux/tarot/salons', ['targetPoints' => 4]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('4 donnes', $state['mine']['targetLabel']);

        // De 3 à 5 joueurs au tarot ; la belote se joue à 4
        $this->api('DELETE', '/api/v1/jeux/tarot/salons/'.$state['mine']['id'].'/joueurs');
        $five = $this->api('POST', '/api/v1/jeux/tarot/salons', ['targetPoints' => 8, 'players' => 5]);
        self::assertResponseStatusCodeSame(201);
        self::assertCount(5, $five['mine']['seats']);

        $this->api('DELETE', '/api/v1/jeux/tarot/salons/'.$five['mine']['id'].'/joueurs');
        $this->api('POST', self::API, ['targetPoints' => 1001, 'players' => 5]);
        self::assertResponseStatusCodeSame(409);
        self::assertStringStartsWith('/jeux/tarot/parties/', $state['mine']['playUrl']);
    }

    public function testSolitaireRoomIsPlayedInOneRoundByTwoToFivePlayers(): void
    {
        $this->client->loginUser($this->createUser('Fred'));

        $state = $this->api('POST', '/api/v1/jeux/solitaire/salons', ['targetPoints' => 1]);

        // Duel solo : la partie du créateur commence aussitôt (le salon reste ouvert aux autres)
        self::assertResponseStatusCodeSame(201);
        self::assertSame('1 manche', $state['mine']['targetLabel']);
        self::assertCount(2, $state['mine']['seats']);
        self::assertFalse($state['mine']['announcements']);
        self::assertSame('playing', $state['mine']['status']);
        self::assertStringStartsWith('/jeux/solitaire/parties/', $state['mine']['playUrl']);

        // On ne quitte pas un duel commencé
        $this->api('DELETE', '/api/v1/jeux/solitaire/salons/'.$state['mine']['id'].'/joueurs');
        self::assertResponseStatusCodeSame(409);

        // Une seule manche
        $this->client->loginUser($this->createUser('Bob'));
        $this->api('POST', '/api/v1/jeux/solitaire/salons', ['targetPoints' => 3]);
        self::assertResponseStatusCodeSame(409);

        // De 2 à 5 joueurs
        $four = $this->api('POST', '/api/v1/jeux/solitaire/salons', ['targetPoints' => 1, 'players' => 4]);
        self::assertResponseStatusCodeSame(201);
        self::assertCount(4, $four['mine']['seats']);

        $this->client->loginUser($this->createUser('Léa'));
        $this->api('POST', '/api/v1/jeux/solitaire/salons', ['targetPoints' => 1, 'players' => 6]);
        self::assertResponseStatusCodeSame(422);

        // 2 joueurs : solitaire seulement (le tarot se joue de 3 à 5)
        $this->api('POST', '/api/v1/jeux/tarot/salons', ['targetPoints' => 4, 'players' => 2]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testSpiderRoomStartsWithItsOwnTableAndWithoutRobots(): void
    {
        // Même un administrateur n'ajoute pas de robot dans un duel solo
        $this->client->loginUser($this->createUser('Fred', admin: true));

        $state = $this->api('POST', '/api/v1/jeux/spider-pro/salons', ['targetPoints' => 1, 'players' => 3]);
        self::assertResponseStatusCodeSame(201);
        self::assertCount(3, $state['mine']['seats']);
        self::assertStringStartsWith('/jeux/spider-pro/parties/', $state['mine']['playUrl']);
        self::assertFalse($state['bots']);

        // Pas de robot dans un duel solo
        $this->api('POST', '/api/v1/jeux/spider-pro/salons/'.$state['mine']['id'].'/places/2/robot');
        self::assertResponseStatusCodeSame(409);

        // Un autre joueur rejoint : sa partie commence aussitôt
        $this->client->loginUser($this->createUser('Bob'));
        $state = $this->api('POST', '/api/v1/jeux/spider-pro/salons/'.$state['mine']['id'].'/places/2');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('/jeux/spider-pro/parties/', $state['mine']['playUrl']);
    }

    public function testAPlayerSitsAtOneTableAndOnlyOnce(): void
    {
        $bob = $this->createUser('Bob');
        $this->client->loginUser($bob);
        $other = $this->api('POST', '/api/v1/jeux/rami/salons', ['targetPoints' => Game::Rami->defaultTargetPoints()])['mine']['id'];

        $this->client->loginUser($this->createUser('Fred'));
        $room = $this->api('POST', '/api/v1/jeux/belote/salons', ['targetPoints' => Game::Belote->defaultTargetPoints()])['mine']['id'];

        // Une deuxième place à sa propre table : il garde la sienne, une seule
        $state = $this->api('POST', '/api/v1/jeux/belote/salons/'.$room.'/places/2');
        self::assertSame(1, \count(array_filter($state['mine']['seats'], static fn (array $seat): bool => $seat['me'] ?? false)));

        // Une autre table (créée ou rejointe), même d'un autre jeu : refusé tant qu'il est assis
        $this->api('POST', '/api/v1/jeux/belote/salons', ['targetPoints' => Game::Belote->defaultTargetPoints()]);
        self::assertResponseStatusCodeSame(409);
        $this->api('POST', '/api/v1/jeux/rami/salons/'.$other.'/places/2');
        self::assertResponseStatusCodeSame(409);
    }

    public function testOnlyAdministratorsAddRobots(): void
    {
        $this->client->loginUser($this->createUser('Fred'));
        $state = $this->api('POST', '/api/v1/jeux/belote/salons', ['targetPoints' => Game::Belote->defaultTargetPoints()]);
        $room = $state['mine']['id'];

        // Joueur ordinaire : pas de bouton « Robot », et la route le refuse
        self::assertFalse($state['bots']);
        $this->api('POST', '/api/v1/jeux/belote/salons/'.$room.'/places/2/robot');
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->api('GET', '/api/v1/jeux/belote/salons')['mine']['seats'][1]['bot']);
    }

    public function testASeatedPlayerAddsARobotInTestMode(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $state = $this->api('POST', '/api/v1/jeux/belote/salons', ['targetPoints' => Game::Belote->defaultTargetPoints()]);
        $room = $state['mine']['id'];
        self::assertTrue($state['bots']);

        // Place inexistante, puis place libre : le robot s'assoit, le salon attend encore deux joueurs
        $this->api('POST', '/api/v1/jeux/belote/salons/'.$room.'/places/9/robot');
        self::assertResponseStatusCodeSame(409);

        $state = $this->api('POST', '/api/v1/jeux/belote/salons/'.$room.'/places/2/robot');
        self::assertResponseIsSuccessful();
        self::assertTrue($state['mine']['seats'][1]['bot']);
        self::assertSame('waiting', $state['mine']['status']);

        // Un joueur qui n'est pas assis à cette table n'y ajoute rien
        $this->client->loginUser($this->createUser('Curieux', admin: true));
        $this->api('POST', '/api/v1/jeux/belote/salons/'.$room.'/places/3/robot');
        self::assertResponseStatusCodeSame(409);
    }

    public function testPointsMustMatchTheGame(): void
    {
        $this->client->loginUser($this->createUser('Fred'));

        // 501 points : belote seulement (701 en contrée)
        $this->api('POST', '/api/v1/jeux/contree/salons', ['targetPoints' => 501]);
        self::assertResponseStatusCodeSame(409);

        // Tarot : 4, 8 ou 12 donnes
        $this->api('POST', '/api/v1/jeux/tarot/salons', ['targetPoints' => 1001]);
        self::assertResponseStatusCodeSame(409);
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

    private function createUser(string $pseudo, bool $admin = false): User
    {
        $user = new User(bin2hex(random_bytes(6)).'@exemple.fr', $pseudo);
        $user->setPassword('pas-utilise-dans-ces-tests');
        $user->markEmailAsVerified();

        if ($admin) {
            $user->grantRole(User::ROLE_ADMIN);
        }
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
