<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PatienceGame;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\Game;
use App\Service\GameRoomManager;
use App\Service\PatienceTable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * « Mes parties » : le tableau des parties du joueur (duels solo et jeux à plusieurs), avec leur classement.
 */
final class DuelControllerTest extends WebTestCase
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

    public function testVisitorsMustLogIn(): void
    {
        $this->client->request('GET', '/mes-parties');

        self::assertResponseRedirects();
    }

    public function testTheOldAddressMovedToMesParties(): void
    {
        $this->client->loginUser($this->createUser('Fred'));
        $this->client->request('GET', '/mes-duels');

        self::assertResponseRedirects('/mes-parties', 301);
    }

    public function testWithoutDuels(): void
    {
        $this->client->loginUser($this->createUser('Fred'));
        $this->client->request('GET', '/mes-parties');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main#mes-parties');
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('.duels__tab--active', 'En cours');
        self::assertSelectorTextContains('.duels__empty', 'Aucune partie en cours');
        // Le lien du menu principal
        self::assertSelectorExists('.site-nav a[href="/mes-parties"][aria-current="page"]');

        $this->client->request('GET', '/mes-parties/terminees');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.duels__tab--active', 'Terminées');
        self::assertSelectorTextContains('.duels__empty', 'Aucune partie terminée');

        $this->client->request('GET', '/mes-parties?page=2');
        self::assertResponseStatusCodeSame(404);
    }

    public function testScoresAppearOnceMyGameIsOver(): void
    {
        $fred = $this->createUser('Fred');
        $bob = $this->createUser('Bob');
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($fred, Game::Solitaire, 1, false, 3);
        $manager->join($bob, $room);
        $game = $this->entityManager->getRepository(PatienceGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');

        // Les deux parties commencent tout de suite ; Fred arrête la sienne
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE solitaire_board SET started_at = :start WHERE game_id = :game',
            ['start' => (new \DateTimeImmutable('-10 seconds'))->format('Y-m-d H:i:s'), 'game' => $game->getId()],
        );
        $this->entityManager->clear();
        $game = $this->entityManager->find(PatienceGame::class, $game->getId()) ?? self::fail('Partie introuvable.');
        $fred = $this->entityManager->find(User::class, $fred->getId()) ?? self::fail('Joueur introuvable.');
        static::getContainer()->get(PatienceTable::class)->stop($fred, $game);

        // Fred : son score, Bob encore en cours, une place libre ; le duel est mis en avant
        $this->client->loginUser($fred);
        $crawler = $this->client->request('GET', '/mes-parties?duel='.$room->getId());

        // Le duel est « en cours » (Bob joue encore, une place est libre) : Fred, son score ; Bob, les petits points
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.duels__row');
        self::assertSelectorExists('.duels__row--highlight');
        self::assertSelectorTextContains('.duels__game-name', 'Solitaire');
        self::assertSelectorExists('a.duels__ref[href="/mes-parties/'.$room->getId().'"]');
        self::assertSelectorTextContains('.duels__cell--type', '3 joueurs');
        self::assertSelectorTextContains('.duels__player--me', 'Fred');
        self::assertMatchesRegularExpression('/^[\d ]+$/', trim($crawler->filter('.duels__points--me')->text()));
        self::assertSelectorCount(3, '.duels__dot');
        self::assertSelectorCount(1, '.duels__player--free');
        self::assertSelectorTextContains('.duels__tab-count', '1');

        $this->client->request('GET', '/mes-parties/terminees');
        self::assertSelectorCount(0, '.duels__row');

        // Bob joue encore : le score de Fred est caché, et le lien pour reprendre sa partie
        $this->client->loginUser($this->entityManager->find(User::class, $bob->getId()) ?? self::fail('Joueur introuvable.'));
        $crawler = $this->client->request('GET', '/mes-parties');

        self::assertSelectorExists('a.duels__resume[href="/jeux/solitaire/parties/'.$room->getId().'"]');
        self::assertSame('caché', trim($crawler->filter('.duels__points')->first()->text()));
    }

    public function testADuelIsFinishedOnlyOnceEveryoneHasPlayed(): void
    {
        $fred = $this->createUser('Fred');
        $bob = $this->createUser('Bob');
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($fred, Game::Solitaire, 1, false, 2);
        $manager->join($bob, $room);
        $gameId = ($this->entityManager->getRepository(PatienceGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.'))->getId();
        $table = static::getContainer()->get(PatienceTable::class);

        // Fred a fini (il a quitté sa partie : son score compte), Bob joue encore : « En cours » pour les deux
        $this->entityManager->clear();
        $table->abandon($this->entityManager->find(User::class, $fred->getId()) ?? self::fail(), $this->entityManager->find(PatienceGame::class, $gameId) ?? self::fail());
        $this->client->loginUser($this->entityManager->find(User::class, $fred->getId()) ?? self::fail());
        $this->client->request('GET', '/mes-parties');
        self::assertSelectorCount(1, '.duels__row');
        $this->client->request('GET', '/mes-parties/terminees');
        self::assertSelectorCount(0, '.duels__row');

        // Bob a fini à son tour : le duel passe dans « Terminées »
        $this->entityManager->clear();
        $table->abandon($this->entityManager->find(User::class, $bob->getId()) ?? self::fail(), $this->entityManager->find(PatienceGame::class, $gameId) ?? self::fail());
        $this->client->request('GET', '/mes-parties');
        self::assertSelectorCount(0, '.duels__row');
        $this->client->request('GET', '/mes-parties/terminees');
        self::assertSelectorCount(1, '.duels__row');
        self::assertSelectorTextContains('.duels__tab--active .duels__tab-count', '1');
    }

    public function testAClosedDuelWhereSomeoneHasNotPlayedStaysInProgress(): void
    {
        [$fred, $bob, $room] = $this->fredStopped();

        // Salon fermé par erreur (anciennes versions) alors que Bob n'a pas fini et qu'une place est libre
        $this->entityManager->getConnection()->executeStatement("UPDATE game_room SET status = 'closed' WHERE id = :id", ['id' => $room->getId()]);

        $this->client->loginUser($fred);
        $this->client->request('GET', '/mes-parties/terminees');
        self::assertSelectorCount(0, '.duels__row');
        $this->client->request('GET', '/mes-parties');
        self::assertSelectorCount(1, '.duels__row');

        // Bob finit aussi : il reste une place libre, la partie est toujours en cours
        $this->entityManager->clear();
        $game = $this->entityManager->getRepository(PatienceGame::class)->findOneBy(['room' => $room->getId()]) ?? self::fail();
        static::getContainer()->get(PatienceTable::class)->abandon($this->entityManager->find(User::class, $bob->getId()) ?? self::fail(), $game);
        $this->client->request('GET', '/mes-parties/terminees');
        self::assertSelectorCount(0, '.duels__row');
        self::assertSelectorTextContains('.duels__empty', 'Aucune partie terminée');
        $this->client->request('GET', '/mes-parties');
        self::assertSelectorCount(1, '.duels__row');
        self::assertSelectorCount(1, '.duels__player--free');
    }

    public function testTableGamesAreThereToo(): void
    {
        $fred = $this->createUser('Fred');
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($fred, Game::Belote, Game::Belote->defaultTargetPoints(), false);

        foreach ([2, 3, 4] as $position) {
            $manager->addBot($fred, $room, $position);
        }

        // La partie a commencé : « En cours », avec les quatre joueurs et le lien pour la reprendre
        $this->client->loginUser($fred);
        $this->client->request('GET', '/mes-parties');
        self::assertSelectorCount(1, '.duels__row');
        self::assertSelectorTextContains('.duels__game-name', 'Belote');
        self::assertSelectorTextContains('.duels__ref', 'Partie n° '.$room->getId());
        self::assertSelectorNotExists('a.duels__ref');
        self::assertSelectorTextContains('.duels__cell--type', '2 contre 2');
        self::assertSelectorCount(4, '.duels__player');
        self::assertSelectorTextContains('.duels__cell--ranking', 'Robot');
        self::assertSelectorExists('a.duels__resume[href="/jeux/belote/parties/'.$room->getId().'"]');
        $this->client->request('GET', '/mes-parties/terminees');
        self::assertSelectorCount(0, '.duels__row');

        // Fin de la partie : l'équipe de Fred (places 1 et 3) gagne
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement("UPDATE game_room SET status = 'closed', finished_at = NOW() WHERE id = :id", ['id' => $room->getId()]);
        $connection->executeStatement('UPDATE game_player SET team_score = IF(position % 2 = 1, 1012, 640), won = IF(position % 2 = 1, 1, 0) WHERE room_id = :id', ['id' => $room->getId()]);

        $crawler = $this->client->request('GET', '/mes-parties/terminees');
        self::assertSelectorCount(1, '.duels__row');
        self::assertSame('Fred', trim($crawler->filter('.duels__player')->first()->text()));
        self::assertSame('1 012', trim($crawler->filter('.duels__points')->first()->text()));
        self::assertSelectorNotExists('.duels__resume');
        $this->client->request('GET', '/mes-parties');
        self::assertSelectorCount(0, '.duels__row');
    }

    public function testTheResultPageShowsThisDuelOnly(): void
    {
        [$fred, $bob, $room] = $this->fredStopped();

        $this->client->loginUser($fred);
        $this->client->request('GET', '/mes-parties/'.$room->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main#resultat-duel');
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('.duel-result__game', 'Solitaire · duel n° '.$room->getId());
        self::assertSelectorTextContains('.duel-result__status', 'Le duel continue');
        // Fred : son score et le rang 1 ; Bob joue encore (les petits points) ; une place attend un joueur
        self::assertSelectorCount(3, '.duel-result__player');
        self::assertSelectorTextContains('.duel-result__player--me', 'Fred');
        self::assertSelectorTextContains('.duel-result__player--me .duel-result__score', 'points');
        self::assertSelectorTextContains('.duel-result__player--me .duel-result__rank', '1');
        self::assertSelectorCount(3, '.duel-result__dot');
        self::assertSelectorTextContains('.duel-result__player--free', 'en attente d’un joueur');
        self::assertSelectorExists('a[href="/jeux/solitaire"]');

        // L'API (page qui se met à jour, application mobile) donne le même classement
        $this->client->request('GET', '/api/v1/duels/'.$room->getId(), [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $duel = json_decode((string) $this->client->getResponse()->getContent(), true)['duel'];
        self::assertSame($room->getId(), $duel['id']);
        self::assertSame('solitaire', $duel['game']);
        self::assertTrue($duel['waiting']);
        self::assertSame(['fini', 'en-cours', 'libre'], array_column($duel['ranking'], 'state'));
        self::assertSame([1, null, null], array_column($duel['ranking'], 'rank'));
        self::assertArrayNotHasKey('email', $duel['ranking'][0]);

        // Bob joue encore : il ne voit pas le score de Fred et peut reprendre sa partie
        $this->client->loginUser($bob);
        $this->client->request('GET', '/mes-parties/'.$room->getId());
        self::assertSelectorTextContains('.duel-result__status', 'Votre partie est en cours');
        self::assertSelectorExists('a[href="/jeux/solitaire/parties/'.$room->getId().'"]');
        self::assertSelectorTextNotContains('.duel-result__ranking', 'points');
    }

    public function testOnlyThePlayersOfTheDuelSeeIt(): void
    {
        [, , $room] = $this->fredStopped();

        $this->client->loginUser($this->createUser('Lea'));
        $this->client->request('GET', '/mes-parties/'.$room->getId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/v1/duels/'.$room->getId(), [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/mes-parties/999999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testVisitorsMustLogInToSeeAResult(): void
    {
        [, , $room] = $this->fredStopped();

        $this->client->request('GET', '/mes-parties/'.$room->getId());
        self::assertResponseRedirects();

        $this->client->request('GET', '/api/v1/duels/'.$room->getId(), [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Un duel de 3 : Fred a arrêté sa partie, Bob joue encore, une place est libre.
     *
     * @return array{User, User, \App\Entity\GameRoom}
     */
    private function fredStopped(): array
    {
        $fred = $this->createUser('Fred');
        $bob = $this->createUser('Bob');
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($fred, Game::Solitaire, 1, false, 3);
        $manager->join($bob, $room);
        $game = $this->entityManager->getRepository(PatienceGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');
        $this->entityManager->clear();
        $game = $this->entityManager->find(PatienceGame::class, $game->getId()) ?? self::fail('Partie introuvable.');
        $fred = $this->entityManager->find(User::class, $fred->getId()) ?? self::fail('Joueur introuvable.');
        static::getContainer()->get(PatienceTable::class)->stop($fred, $game);

        return [$fred, $this->entityManager->find(User::class, $bob->getId()) ?? self::fail('Joueur introuvable.'), $game->getRoom()];
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

        foreach (['golf_hole', 'golf_game', 'huit_round', 'huit_game', 'rami_round', 'rami_game', 'solitaire_board', 'solitaire_game', 'tarot_deal', 'tarot_game', 'belote_deal', 'belote_game', 'game_player', 'game_room_seat', 'game_room', 'reset_password_request', 'profile', '`user`'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
    }
}
