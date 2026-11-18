<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\GameRoom;
use App\Entity\Profile;
use App\Entity\RamiGame;
use App\Entity\RamiRound;
use App\Entity\User;
use App\Enum\Game;
use App\Enum\RoomStatus;
use App\Game\Rami\RamiCard;
use App\Service\GameRoomManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Partie de rami contre des robots (PLAYORA_BOTS=1 dans .env.test) : chaque route de l'API.
 * Les robots jouent aussitôt après chaque coup : c'est toujours au joueur de jouer quand il reçoit sa vue.
 */
final class RamiApiControllerTest extends WebTestCase
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

    public function testTheGameStartsWithThirteenCardsEach(): void
    {
        [$user, $game] = $this->gameAgainstBots(4);
        $this->client->loginUser($user);

        $view = $this->api('GET', $this->url($game))['view'];

        self::assertSame(RoomStatus::Playing, $game->getRoom()->getStatus());
        self::assertSame('jeu', $view['phase']);
        self::assertSame($view['me'], $view['turn']);
        self::assertCount(4, $view['players']);
        self::assertContains(\count($view['hand']), [13, 14]);
        self::assertSame(51, $view['firstMeld']);
        self::assertSame(500, $view['target']);
        // Des autres joueurs, seulement le nombre de cartes
        self::assertArrayNotHasKey('hands', $view);
        self::assertArrayHasKey('cards', $view['players'][0]);

        $this->client->request('GET', '/jeux/rami/parties/'.$game->getRoom()->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main#partie.rami');
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('h1', 'rami');
    }

    public function testATurnIsDrawThenDiscard(): void
    {
        [$user, $game] = $this->gameAgainstBots(3);
        $this->client->loginUser($user);
        $view = $this->api('GET', $this->url($game))['view'];

        if ('pioche' === $view['step']) {
            $body = $this->api('POST', $this->url($game).'/defausse', ['card' => $view['hand'][0]]);
            self::assertResponseStatusCodeSame(409);
            self::assertSame('Prenez d’abord une carte (pioche ou défausse).', $body['detail']);

            $view = $this->api('POST', $this->url($game).'/pioche')['view'];
            self::assertResponseIsSuccessful();
            self::assertSame('jeu', $view['step']);
            self::assertCount(14, $view['hand']);
        }

        $this->api('POST', $this->url($game).'/pioche');
        self::assertResponseStatusCodeSame(409);

        $version = $view['version'];
        $view = $this->api('POST', $this->url($game).'/defausse', ['card' => $view['hand'][0]])['view'];

        // Les robots ont joué : c'est de nouveau à moi (ou une nouvelle manche a commencé)
        self::assertResponseIsSuccessful();
        self::assertGreaterThan($version, $view['version']);
        self::assertSame($view['me'], $view['turn']);
    }

    public function testACardThatIsNotInTheHandIsRefused(): void
    {
        [$user, $game] = $this->gameAgainstBots(2);
        $this->client->loginUser($user);
        $view = $this->api('GET', $this->url($game))['view'];

        if ('pioche' === $view['step']) {
            $view = $this->api('POST', $this->url($game).'/pioche')['view'];
        }

        $missing = array_values(array_diff(array_map(static fn (RamiCard $card): string => $card->code(), RamiCard::deck()), $view['hand']));
        $body = $this->api('POST', $this->url($game).'/poses', ['groups' => [\array_slice($missing, 0, 3)]]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Cette carte n’est pas dans votre main.', $body['detail']);
        self::assertArrayHasKey('view', $body);

        $body = $this->api('POST', $this->url($game).'/ajouts', ['card' => $view['hand'][0], 'meld' => 0]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Faites d’abord votre première pose (51 points, dont une suite).', $body['detail']);

        $this->api('POST', $this->url($game).'/defausse/rendre');
        self::assertResponseStatusCodeSame(409);
    }

    public function testInvalidRequestsAreRejectedBeforeReachingTheGame(): void
    {
        [$user, $game] = $this->gameAgainstBots(2);
        $this->client->loginUser($user);

        $this->api('POST', $this->url($game).'/poses', ['groups' => []]);
        self::assertResponseStatusCodeSame(422);

        $this->api('POST', $this->url($game).'/poses', ['groups' => [['coeur-7-1', 'coeur-8-1']]]);
        self::assertResponseStatusCodeSame(422);

        $this->api('POST', $this->url($game).'/defausse', ['card' => 'coeur-7']);
        self::assertResponseStatusCodeSame(422);

        $this->api('POST', $this->url($game).'/ajouts', ['card' => 'joker-5', 'meld' => 0]);
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
            'UPDATE rami_game SET turn_started_at = :start WHERE id = :id',
            ['start' => (new \DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s'), 'id' => $game->getId()],
        );
        $version = $this->api('GET', $this->url($game))['view']['version'];
        $view = $this->api('POST', $this->url($game).'/temps-ecoule')['view'];

        // Le serveur a joué pour moi, les robots ont suivi : c'est de nouveau à moi
        self::assertResponseIsSuccessful();
        self::assertGreaterThan($version, $view['version']);
        self::assertSame($view['me'], $view['turn']);
    }

    public function testWhenTheLastPlayerLeavesTheRobotsFinishTheGame(): void
    {
        [$user, $game] = $this->gameAgainstBots(2, 300);
        $this->client->loginUser($user);

        $this->client->request('POST', $this->url($game).'/abandon', [], [], ['HTTP_X_CSRF_TOKEN' => 'csrf-token']);
        self::assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $room = $this->entityManager->find(GameRoom::class, $game->getRoom()->getId()) ?? self::fail('Salon introuvable.');
        self::assertSame(RoomStatus::Closed, $room->getStatus());
        self::assertNotEmpty($this->entityManager->getRepository(RamiRound::class)->findBy(['game' => $game->getId()]));
        self::assertGreaterThanOrEqual(300, max($this->entityManager->find(RamiGame::class, $game->getId())?->getScores() ?? [0]));

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

        $this->api('GET', '/api/v1/jeux/rami/parties/999999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testActionsNeedTheFormToken(): void
    {
        [$user, $game] = $this->gameAgainstBots(2);
        $this->client->loginUser($user);

        $this->client->request('POST', $this->url($game).'/pioche', [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array{User, RamiGame}
     */
    private function gameAgainstBots(int $players, int $target = 500): array
    {
        $user = $this->createUser('Fred');
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($user, Game::Rami, $target, false, $players);

        foreach (range(2, $players) as $position) {
            $manager->addBot($user, $room, $position);
        }

        $game = $this->entityManager->getRepository(RamiGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');

        return [$user, $game];
    }

    private function url(RamiGame $game): string
    {
        return '/api/v1/jeux/rami/parties/'.$game->getId();
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

        foreach (['rami_round', 'rami_game', 'solitaire_board', 'solitaire_game', 'tarot_deal', 'tarot_game', 'belote_deal', 'belote_game', 'game_player', 'game_room_seat', 'game_room', 'reset_password_request', 'profile', '`user`'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
    }
}
