<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\GameRoom;
use App\Entity\HuitGame;
use App\Entity\HuitRound;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\Game;
use App\Enum\RoomStatus;
use App\Game\Huit\HuitCard;
use App\Service\GameRoomManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Partie de 8 américain contre des robots (PLAYORA_BOTS=1 dans .env.test) : chaque route de l'API.
 * Les robots jouent aussitôt après chaque coup : c'est toujours au joueur de jouer quand il reçoit sa vue.
 */
final class HuitApiControllerTest extends WebTestCase
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

    public function testTheGameStartsWithEightCardsEachAtTwoAndSevenBeyond(): void
    {
        [$user, $game] = $this->gameAgainstBots(2);
        $this->client->loginUser($user);

        $view = $this->api('GET', $this->url($game))['view'];

        self::assertSame(RoomStatus::Playing, $game->getRoom()->getStatus());
        self::assertSame('jeu', $view['phase']);
        self::assertSame($view['me'], $view['turn']);
        self::assertCount(2, $view['players']);
        self::assertSame(1000, $view['target']);
        self::assertContains($view['suit'], HuitCard::SUITS);
        self::assertNotEmpty($view['pile']);
        // Les cartes jouables viennent du serveur, et sont toutes dans ma main
        self::assertSame([], array_diff($view['playable'], $view['hand']));
        // Des autres joueurs, seulement le nombre de cartes
        self::assertArrayNotHasKey('hands', $view);
        self::assertArrayHasKey('cards', $view['players'][0]);

        $this->client->request('GET', '/jeux/huit-americain/parties/'.$game->getRoom()->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main#partie.huit');
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('h1', '8 américain');
        self::assertSelectorCount(4, '.huit__suit-choice');

        [$user, $game] = $this->gameAgainstBots(3, pseudo: 'Léa');
        $this->client->loginUser($user);
        $view = $this->api('GET', $this->url($game))['view'];
        self::assertCount(3, $view['players']);
        self::assertSame(1, $view['round']);
    }

    public function testAPlayableCardIsPlayedOrACardIsDrawn(): void
    {
        [$user, $game] = $this->gameAgainstBots(3);
        $this->client->loginUser($user);
        $view = $this->api('GET', $this->url($game))['view'];
        $version = $view['version'];

        if ([] === $view['playable']) {
            $view = $this->api('POST', $this->url($game).'/pioche')['view'];
        } else {
            $view = $this->api('POST', $this->url($game).'/cartes', ['card' => $view['playable'][0], 'suit' => 'pique', 'announce' => false])['view'];
        }

        self::assertResponseIsSuccessful();
        self::assertGreaterThan($version, $view['version']);

        // La carte piochée peut être posée : on passe ; sinon les robots ont joué et c'est de nouveau à moi
        if (null !== $view['drawn']) {
            $view = $this->api('POST', $this->url($game).'/passe')['view'];
            self::assertResponseIsSuccessful();
        }

        self::assertSame($view['me'], $view['turn']);
        self::assertNull($view['drawn']);
    }

    public function testRefusedMovesKeepTheGameAsItIs(): void
    {
        [$user, $game] = $this->gameAgainstBots(2);
        $this->client->loginUser($user);
        $view = $this->api('GET', $this->url($game))['view'];

        $missing = array_values(array_diff(array_map(static fn (HuitCard $card): string => $card->code(), HuitCard::deck(1)), $view['hand'], $view['pile']));
        $body = $this->api('POST', $this->url($game).'/cartes', ['card' => $missing[0]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Cette carte n’est pas dans votre main.', $body['detail']);
        self::assertSame($view['version'], $body['view']['version']);

        $body = $this->api('POST', $this->url($game).'/passe');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Piochez d’abord une carte.', $body['detail']);

        $unplayable = array_values(array_diff($view['hand'], $view['playable']));

        if ([] !== $unplayable) {
            $this->api('POST', $this->url($game).'/cartes', ['card' => $unplayable[0]]);
            self::assertResponseStatusCodeSame(409);
        }
    }

    public function testInvalidRequestsAreRejectedBeforeReachingTheGame(): void
    {
        [$user, $game] = $this->gameAgainstBots(2);
        $this->client->loginUser($user);

        $this->api('POST', $this->url($game).'/cartes', ['card' => 'coeur-7']);
        self::assertResponseStatusCodeSame(422);

        $this->api('POST', $this->url($game).'/cartes', ['card' => 'coeur-8-1', 'suit' => 'rouge']);
        self::assertResponseStatusCodeSame(422);

        $this->api('POST', $this->url($game).'/cartes', []);
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
            'UPDATE huit_game SET turn_started_at = :start WHERE id = :id',
            ['start' => (new \DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s'), 'id' => $game->getId()],
        );
        $version = $this->api('GET', $this->url($game))['view']['version'];
        $view = $this->api('POST', $this->url($game).'/temps-ecoule')['view'];

        // Le serveur a joué pour moi (une carte, ou la pioche) : la partie a avancé
        self::assertResponseIsSuccessful();
        self::assertGreaterThan($version, $view['version']);
    }

    public function testWhenTheLastPlayerLeavesTheRobotsFinishTheGame(): void
    {
        [$user, $game] = $this->gameAgainstBots(3, 300);
        $this->client->loginUser($user);

        $this->client->request('POST', $this->url($game).'/abandon', [], [], ['HTTP_X_CSRF_TOKEN' => 'csrf-token']);
        self::assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $room = $this->entityManager->find(GameRoom::class, $game->getRoom()->getId()) ?? self::fail('Salon introuvable.');
        self::assertSame(RoomStatus::Closed, $room->getStatus());
        self::assertNotEmpty($this->entityManager->getRepository(HuitRound::class)->findBy(['game' => $game->getId()]));
        self::assertGreaterThanOrEqual(300, max($this->entityManager->find(HuitGame::class, $game->getId())?->getScores() ?? [0]));

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

        $this->api('GET', '/api/v1/jeux/huit-americain/parties/999999');
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
     * @return array{User, HuitGame}
     */
    private function gameAgainstBots(int $players, int $target = 1000, string $pseudo = 'Fred'): array
    {
        $user = $this->createUser($pseudo);
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($user, Game::HuitAmericain, $target, false, $players);

        foreach (range(2, $players) as $position) {
            $manager->addBot($user, $room, $position);
        }

        $game = $this->entityManager->getRepository(HuitGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');

        return [$user, $game];
    }

    private function url(HuitGame $game): string
    {
        return '/api/v1/jeux/huit-americain/parties/'.$game->getId();
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

        foreach (['huit_round', 'huit_game', 'rami_round', 'rami_game', 'solitaire_board', 'solitaire_game', 'tarot_deal', 'tarot_game', 'belote_deal', 'belote_game', 'game_player', 'game_room_seat', 'game_room', 'reset_password_request', 'profile', '`user`'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
    }
}
