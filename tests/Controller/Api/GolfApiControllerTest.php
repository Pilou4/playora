<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\GameRoom;
use App\Entity\GolfGame;
use App\Entity\GolfHole;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\Game;
use App\Enum\RoomStatus;
use App\Service\GameRoomManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Partie de Golf contre des robots (PLAYORA_BOTS=1 dans .env.test) : chaque route de l'API.
 * Les robots retournent leurs cartes et jouent aussitôt après chaque coup : c'est toujours au joueur de jouer quand il reçoit sa vue.
 */
final class GolfApiControllerTest extends WebTestCase
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

    public function testEachPlayerFirstRevealsTwoCards(): void
    {
        [$user, $game] = $this->gameAgainstBots(4);
        $this->client->loginUser($user);

        $view = $this->api('GET', $this->url($game))['view'];

        self::assertSame(RoomStatus::Playing, $game->getRoom()->getStatus());
        self::assertSame('debut', $view['phase']);
        self::assertSame(1, $view['hole']);
        self::assertSame(9, $view['target']);
        self::assertCount(4, $view['players']);
        self::assertNotNull($view['discard']);

        // Les cartes cachées restent secrètes, même les miennes ; les robots ont déjà retourné leurs 2 cartes
        foreach ($view['players'] as $player) {
            self::assertCount(6, $player['grid']);
            self::assertCount($player['position'] === $view['me'] ? 0 : 2, array_filter($player['grid']));
        }

        self::assertArrayNotHasKey('grids', $view);
        // De la pioche, seulement son nombre de cartes
        self::assertIsInt($view['stock']);

        $this->client->request('GET', '/jeux/golf/parties/'.$game->getRoom()->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main#partie.golf');
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('h1', 'Golf');
    }

    public function testATurnAfterRevealingTwoCards(): void
    {
        [$user, $game] = $this->gameAgainstBots(3);
        $this->client->loginUser($user);

        $this->api('POST', $this->url($game).'/retourner', ['index' => 0]);
        self::assertResponseIsSuccessful();
        $view = $this->api('POST', $this->url($game).'/retourner', ['index' => 3])['view'];
        self::assertResponseIsSuccessful();

        // Tout le monde a retourné ses cartes : les robots ont joué jusqu'à mon tour
        self::assertSame('jeu', $view['phase']);
        self::assertSame($view['me'], $view['turn']);
        self::assertCount(2, array_filter($this->me($view)['grid']));

        $view = $this->api('POST', $this->url($game).'/pioche')['view'];
        self::assertResponseIsSuccessful();
        self::assertNotNull($view['drawn']);
        $drawn = $view['drawn'];
        $version = $view['version'];

        $view = $this->api('POST', $this->url($game).'/echanger', ['index' => 1])['view'];
        self::assertResponseIsSuccessful();
        self::assertGreaterThan($version, $view['version']);
        self::assertSame($view['me'], $view['turn']);
        self::assertNull($view['drawn']);
        self::assertSame($drawn, $this->me($view)['grid'][1]);

        $discard = $view['discard'];
        $view = $this->api('POST', $this->url($game).'/defausse/prendre', ['index' => 2])['view'];
        self::assertResponseIsSuccessful();
        self::assertSame($discard, $this->me($view)['grid'][2]);

        $this->api('POST', $this->url($game).'/pioche');
        $view = $this->api('POST', $this->url($game).'/jeter', ['index' => 4])['view'];
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->me($view)['grid'][4]);
    }

    public function testRefusedMovesKeepTheGameAsItIs(): void
    {
        [$user, $game] = $this->gameAgainstBots(2);
        $this->client->loginUser($user);

        $body = $this->api('POST', $this->url($game).'/pioche');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Retournez d’abord 2 cartes.', $body['detail']);
        self::assertArrayHasKey('view', $body);

        $this->api('POST', $this->url($game).'/retourner', ['index' => 5]);
        $body = $this->api('POST', $this->url($game).'/retourner', ['index' => 5]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Cette carte est déjà retournée.', $body['detail']);

        $this->api('POST', $this->url($game).'/retourner', ['index' => 0]);
        $body = $this->api('POST', $this->url($game).'/jeter', ['index' => 1]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Piochez d’abord une carte.', $body['detail']);

        $this->api('POST', $this->url($game).'/pioche');
        $body = $this->api('POST', $this->url($game).'/jeter', ['index' => 0]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Cette carte est déjà retournée.', $body['detail']);
    }

    public function testInvalidRequestsAreRejectedBeforeReachingTheGame(): void
    {
        [$user, $game] = $this->gameAgainstBots(2);
        $this->client->loginUser($user);

        $this->api('POST', $this->url($game).'/retourner', ['index' => 6]);
        self::assertResponseStatusCodeSame(422);

        $this->api('POST', $this->url($game).'/retourner', ['index' => -1]);
        self::assertResponseStatusCodeSame(422);

        $this->api('POST', $this->url($game).'/echanger', []);
        self::assertResponseStatusCodeSame(422);
    }

    public function testTimeIsUpOnlyAfterTheTurnTime(): void
    {
        [$user, $game] = $this->gameAgainstBots(2);
        $this->client->loginUser($user);

        $body = $this->api('POST', $this->url($game).'/temps-ecoule');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Le temps n’est pas encore écoulé.', $body['detail']);

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE golf_game SET turn_started_at = :start WHERE id = :id',
            ['start' => (new \DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s'), 'id' => $game->getId()],
        );
        $view = $this->api('POST', $this->url($game).'/temps-ecoule')['view'];

        // Le serveur a retourné mes 2 cartes : le jeu a commencé
        self::assertResponseIsSuccessful();
        self::assertSame('jeu', $view['phase']);
        self::assertCount(2, array_filter($this->me($view)['grid']));
    }

    public function testWhenTheLastPlayerLeavesTheRobotsFinishTheGame(): void
    {
        [$user, $game] = $this->gameAgainstBots(3);
        $this->client->loginUser($user);

        $this->client->request('POST', $this->url($game).'/abandon', [], [], ['HTTP_X_CSRF_TOKEN' => 'csrf-token']);
        self::assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $room = $this->entityManager->find(GameRoom::class, $game->getRoom()->getId()) ?? self::fail('Salon introuvable.');
        self::assertSame(RoomStatus::Closed, $room->getStatus());
        // Les 9 trous ont été joués
        self::assertCount(9, $this->entityManager->getRepository(GolfHole::class)->findBy(['game' => $game->getId()]));
        self::assertSame(9, $this->entityManager->find(GolfGame::class, $game->getId())?->getHole());

        // Il n'est plus à la table
        $this->api('GET', $this->url($game));
        self::assertResponseStatusCodeSame(403);
    }

    public function testOnlyThePlayersSeeTheGame(): void
    {
        [, $game] = $this->gameAgainstBots(2);

        $this->client->request('GET', $this->url($game));
        self::assertResponseStatusCodeSame(401);

        $this->client->loginUser($this->createUser('Curieux'));
        $this->api('GET', $this->url($game));
        self::assertResponseStatusCodeSame(403);

        $this->api('GET', '/api/v1/jeux/golf/parties/999999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testActionsNeedTheFormToken(): void
    {
        [$user, $game] = $this->gameAgainstBots(2);
        $this->client->loginUser($user);

        $this->client->request('POST', $this->url($game).'/retourner', [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], '{"index":0}');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, mixed> $view
     *
     * @return array<string, mixed>
     */
    private function me(array $view): array
    {
        foreach ($view['players'] as $player) {
            if ($player['position'] === $view['me']) {
                return $player;
            }
        }

        self::fail('Joueur introuvable.');
    }

    /**
     * @return array{User, GolfGame}
     */
    private function gameAgainstBots(int $players, int $target = 9, string $pseudo = 'Fred'): array
    {
        $user = $this->createUser($pseudo);
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($user, Game::Golf, $target, false, $players);

        foreach (range(2, $players) as $position) {
            $manager->addBot($user, $room, $position);
        }

        $game = $this->entityManager->getRepository(GolfGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');

        return [$user, $game];
    }

    private function url(GolfGame $game): string
    {
        return '/api/v1/jeux/golf/parties/'.$game->getId();
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
        ], json_encode($body ?? new \stdClass(), \JSON_THROW_ON_ERROR));

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

        foreach (['golf_hole', 'golf_game', 'huit_round', 'huit_game', 'rami_round', 'rami_game', 'solitaire_board', 'solitaire_game', 'tarot_deal', 'tarot_game', 'belote_deal', 'belote_game', 'game_player', 'game_room_seat', 'game_room', 'reset_password_request', 'profile', '`user`'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
    }
}
