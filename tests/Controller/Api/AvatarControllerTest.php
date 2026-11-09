<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Profile;
use App\Entity\User;
use App\Repository\ProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AvatarControllerTest extends WebTestCase
{
    private const string URL = '/api/v1/moi/avatar';
    private const string CODE = 'portrait.1.4.0.0.9.6.2.3.0.0.1.0.1.4.1.0.0.0.1';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
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

    public function testPiecesArePublic(): void
    {
        $this->client->request('GET', '/api/v1/avatars/pieces');

        self::assertResponseIsSuccessful();
        $catalog = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['personnage', 'animal'], array_column($catalog['styles'], 'key'));
        self::assertNotEmpty($catalog['gallery']);
    }

    public function testSavingNeedsAnAccount(): void
    {
        $this->put(['code' => self::CODE]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testPlayerSavesAnAvatar(): void
    {
        $user = $this->createUser('Fred');
        $this->client->loginUser($user);

        $body = $this->put(['code' => self::CODE]);

        self::assertResponseIsSuccessful();
        self::assertSame('/avatars/'.self::CODE.'.svg?v=5', $body['url']);
        self::assertSame('/avatars/'.self::CODE.'-visage.svg?v=5', $body['face']);
        self::assertSame(self::CODE, $this->avatarOf($user));
    }

    public function testPlayerGoesBackToTheInitial(): void
    {
        $user = $this->createUser('Fred', self::CODE);
        $this->client->loginUser($user);

        $body = $this->put(['code' => null]);

        self::assertResponseIsSuccessful();
        self::assertNull($body['url']);
        self::assertNull($this->avatarOf($user));
    }

    public function testUnknownAvatarIsRefused(): void
    {
        $user = $this->createUser('Fred');
        $this->client->loginUser($user);

        $body = $this->put(['code' => 'portrait.99.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0']);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertStringContainsString('n’existe pas', $body['detail']);
        self::assertNull($this->avatarOf($user));
    }

    public function testRequestFromAnotherSiteIsRefused(): void
    {
        $this->client->loginUser($this->createUser('Fred'));

        $this->client->request('PUT', self::URL, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
            'HTTP_ORIGIN' => 'https://pirate.example',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ], json_encode(['code' => self::CODE], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(403);
    }

    public function testLobbyShowsTheAvatarInsteadOfTheInitial(): void
    {
        $this->client->loginUser($this->createUser('Fred', self::CODE));
        $this->client->request('POST', '/api/v1/jeux/belote/salons', [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], json_encode(['targetPoints' => 1001], \JSON_THROW_ON_ERROR));

        $state = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('/avatars/'.self::CODE.'-visage.svg?v=5', $state['mine']['seats'][0]['avatar']);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function put(array $body): array
    {
        $this->client->request('PUT', self::URL, [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], json_encode($body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function avatarOf(User $user): ?string
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $fresh = $entityManager->find(User::class, $user->getId()) ?? self::fail('Compte introuvable.');

        return static::getContainer()->get(ProfileRepository::class)->findOneByUser($fresh)?->getAvatar();
    }

    private function createUser(string $pseudo, ?string $avatar = null): User
    {
        $user = new User(bin2hex(random_bytes(6)).'@exemple.fr', $pseudo);
        $user->setPassword('pas-utilise-dans-ces-tests');
        $user->markEmailAsVerified();
        $profile = new Profile($user);
        $profile->setAvatar($avatar);
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
