<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Profile;
use App\Entity\User;
use App\Enum\Game;
use App\Service\GameRoomManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameControllerTest extends WebTestCase
{
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

    public function testLobbyPageIsPublic(): void
    {
        $crawler = $this->client->request('GET', '/jeux/belote');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('h1', 'Belote');
        self::assertSelectorExists('main#salons');
        self::assertSelectorExists('#regles[role="tabpanel"]');
        self::assertSelectorExists('dialog#creer-un-salon');
        self::assertSelectorCount(16, '#regles .rules__card');
        self::assertSelectorCount(6, '#regles .rules__announce');
        self::assertSelectorNotExists('#annonces[checked]');
        self::assertSame(['rooms' => [], 'mine' => null], json_decode((string) $crawler->filter('main#salons')->attr('data-state'), true));
    }

    public function testPageContainsNoActionLink(): void
    {
        $this->client->request('GET', '/jeux/belote');

        // Toutes les actions passent par l'API en JS : aucun formulaire ni lien d'action dans la liste
        self::assertSelectorNotExists('.lobby__list form');
        self::assertSelectorNotExists('.lobby__list a');
    }

    public function testInitialStateListsRoomsWithoutPersonalData(): void
    {
        $host = $this->createUser('Fred');
        static::getContainer()->get(GameRoomManager::class)->create($host, Game::Belote, 501, false);

        $crawler = $this->client->request('GET', '/jeux/belote');
        $raw = (string) $crawler->filter('main#salons')->attr('data-state');
        $state = json_decode($raw, true);

        self::assertCount(1, $state['rooms']);
        self::assertSame(501, $state['rooms'][0]['targetPoints']);
        self::assertSame('Fred', $state['rooms'][0]['seats'][0]['pseudo']);
        self::assertStringNotContainsString('@', $raw);
    }

    public function testUnknownGameIsNotFound(): void
    {
        $this->client->request('GET', '/jeux/echecs');

        self::assertResponseStatusCodeSame(404);
    }

    private function createUser(string $pseudo): User
    {
        $user = new User(bin2hex(random_bytes(6)).'@exemple.fr', $pseudo);
        $user->setPassword('pas-utilise-dans-ces-tests');
        $user->markEmailAsVerified();
        $this->entityManager->persist($user);
        $this->entityManager->persist(new Profile($user));
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
