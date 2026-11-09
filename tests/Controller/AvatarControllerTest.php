<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Profile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AvatarControllerTest extends WebTestCase
{
    private const string CODE = 'portrait.0.0.1.0.2.2.0.0.0.0.0.0.0.1.0.0.0.0.1';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testImageIsAnSvgKeptByTheBrowser(): void
    {
        $this->client->request('GET', '/avatars/'.self::CODE.'.svg');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertStringContainsString('immutable', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringStartsWith('<svg', (string) $this->client->getResponse()->getContent());
    }

    public function testFaceIsASquareImage(): void
    {
        $this->client->request('GET', '/avatars/'.self::CODE.'-visage.svg');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        self::assertStringContainsString('width="180" height="180"', (string) $this->client->getResponse()->getContent());
    }

    public function testUnknownAvatarIsNotFound(): void
    {
        $this->client->request('GET', '/avatars/portrait.0.0.svg');

        self::assertResponseStatusCodeSame(404);
    }

    public function testPageNeedsAnAccount(): void
    {
        $this->client->request('GET', '/mon-profil/avatar');

        self::assertResponseRedirects();
        self::assertStringContainsString('/connexion', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testPageShowsTheEditor(): void
    {
        $this->client->loginUser($this->createUser('Fred', self::CODE));

        $crawler = $this->client->request('GET', '/mon-profil/avatar');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorExists('main#avatar.avatar-page');
        self::assertSame(self::CODE, $crawler->filter('main#avatar')->attr('data-code'));
        self::assertStringContainsString('/avatars/'.self::CODE.'.svg', (string) $crawler->filter('.avatar-editor__image')->attr('src'));
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
