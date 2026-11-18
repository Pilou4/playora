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
        self::assertSame(['rooms' => [], 'mine' => null, 'bots' => true], json_decode((string) $crawler->filter('main#salons')->attr('data-state'), true));
    }

    public function testCoincheAndContreeHaveTheirOwnLobby(): void
    {
        foreach (['coinche' => 'Coinche', 'contree' => 'Contrée'] as $slug => $label) {
            $this->client->request('GET', '/jeux/'.$slug);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', $label);
            // Partie en 701 points par défaut, comme sur GameDuell
            self::assertSelectorExists('input[name="targetPoints"][value="701"]:checked');
        }
    }

    public function testTarotLobbyIsPlayedInDeals(): void
    {
        $this->client->request('GET', '/jeux/tarot');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tarot');
        self::assertSelectorExists('input[name="targetPoints"][value="4"]:checked');
        self::assertSelectorNotExists('input[name="announcements"]');
        self::assertSelectorTextContains('#regles', 'bouts');
    }

    public function testSolitaireLobbyOffersTwoToFivePlayersInOneRound(): void
    {
        $this->client->request('GET', '/jeux/solitaire');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Solitaire');
        self::assertSelectorTextContains('.lobby__lead', 'De 2 à 5 joueurs');
        // Une seule manche : rien à choisir, ni dans la fenêtre ni dans les onglets
        self::assertSelectorExists('input[type="hidden"][name="targetPoints"][value="1"]');
        self::assertSelectorNotExists('input[type="radio"][name="targetPoints"]');
        self::assertSelectorNotExists('.tabs__tab[data-filter="1"]');
        self::assertSelectorNotExists('input[name="announcements"]');
        // De 2 à 5 joueurs, 2 par défaut
        self::assertSelectorCount(4, 'input[name="players"]');
        self::assertSelectorExists('input[name="players"][value="2"]:checked');
        self::assertSelectorTextContains('#regles', 'familles');
        // Solitaire : 3 cartes au talon ; Solitaire harmonie : une seule
        self::assertSelectorTextContains('#regles', 'retourner 3 cartes à la fois');

        $this->client->request('GET', '/jeux/solitaire-harmonie');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Solitaire harmonie');
        self::assertSelectorTextContains('#regles', 'une carte à la fois');
    }

    public function testSpiderLobbiesShowTheirOwnRules(): void
    {
        foreach (['spider-debutant' => 'Spider débutant', 'spider' => 'Spider', 'spider-pro' => 'Spider Pro'] as $game => $label) {
            $this->client->request('GET', '/jeux/'.$game);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', $label);
            self::assertSelectorTextContains('.lobby__lead', 'De 2 à 5 joueurs');
            self::assertSelectorExists('input[type="hidden"][name="targetPoints"][value="1"]');
            self::assertSelectorCount(4, 'input[name="players"]');
            self::assertSelectorTextContains('#regles', 'familles complètes');
        }

        // Au Pro : le barème plus fort et les deux piles du Joker
        $this->client->request('GET', '/jeux/spider-pro');
        self::assertSelectorTextContains('#regles', '5 000');
        self::assertSelectorTextContains('#regles', 'Deux piles du Joker');
    }

    public function testRamiLobbyOffersTwoToSixPlayersAndPenaltyPoints(): void
    {
        $this->client->request('GET', '/jeux/rami');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Rami');
        self::assertSelectorTextContains('.lobby__lead', 'De 2 à 6 joueurs');
        self::assertSelectorCount(5, 'input[name="players"]');
        self::assertSelectorExists('input[name="players"][value="4"]:checked');
        self::assertSelectorCount(3, 'input[name="targetPoints"]');
        self::assertSelectorExists('input[name="targetPoints"][value="500"]:checked');
        self::assertSelectorNotExists('input[name="announcements"]');
        self::assertSelectorTextContains('#regles', '51 points');
        self::assertSelectorTextNotContains('.dialog__note', 'prendre ou passer');
    }

    public function testHuitLobbyOffersTwoToSixPlayersAndPenaltyPoints(): void
    {
        $this->client->request('GET', '/jeux/huit-americain');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', '8 américain');
        self::assertSelectorTextContains('.lobby__lead', 'De 2 à 6 joueurs');
        self::assertSelectorCount(5, 'input[name="players"]');
        self::assertSelectorCount(3, 'input[name="targetPoints"]');
        self::assertSelectorExists('input[name="targetPoints"][value="300"]:checked');
        self::assertSelectorNotExists('input[name="announcements"]');
        self::assertSelectorTextContains('#regles', 'Carte !');
    }

    public function testGolfLobbyOffersTwoToSixPlayersAndNineOrEighteenHoles(): void
    {
        $this->client->request('GET', '/jeux/golf');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Golf');
        self::assertSelectorTextContains('.lobby__lead', 'De 2 à 6 joueurs');
        self::assertSelectorCount(5, 'input[name="players"]');
        self::assertSelectorCount(2, 'input[name="targetPoints"]');
        self::assertSelectorExists('input[name="targetPoints"][value="9"]:checked');
        self::assertSelectorTextContains('label[for="points-18"]', '18 trous');
        self::assertSelectorNotExists('input[name="announcements"]');
        self::assertSelectorTextContains('#regles', 'même colonne');
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
