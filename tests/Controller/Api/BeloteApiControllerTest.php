<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\BeloteDeal;
use App\Entity\BeloteGame;
use App\Entity\GamePlayer;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\Game;
use App\Enum\RoomStatus;
use App\Service\GameRoomManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Parties de belote contre 3 robots (PLAYORA_BOTS=1 dans .env.test).
 */
final class BeloteApiControllerTest extends WebTestCase
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

    public function testThreeBotsCompleteTheTableAndTheGameStarts(): void
    {
        [$user, $game] = $this->gameAgainstBots();

        self::assertSame(RoomStatus::Playing, $game->getRoom()->getStatus());
        self::assertCount(4, $this->entityManager->getRepository(GamePlayer::class)->findBy(['room' => $game->getRoom()]));
        self::assertCount(3, array_filter($game->getRoom()->getSeats(), static fn ($seat): bool => $seat->isBot()));

        $this->client->loginUser($user);
        $this->client->request('GET', '/jeux/belote/parties/'.$game->getRoom()->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main#partie.belote');
        self::assertSelectorCount(1, 'h1');
    }

    public function testViewShowsOnlyMyOwnCards(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);

        $view = $this->api('GET', $this->url($game))['view'];
        $state = $game->getState()->toArray();

        self::assertSame($state['hands'][$view['me']], $view['hand']);
        self::assertSame(array_map('count', $state['hands']), $view['counts']);
        self::assertArrayNotHasKey('hands', $view);
        self::assertCount(4, $view['players']);
    }

    public function testWholeGameIsPlayedAndRecorded(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);
        $view = $this->api('GET', $this->url($game))['view'];

        for ($actions = 0; 'terminee' !== $view['phase']; ++$actions) {
            self::assertLessThan(400, $actions, 'La partie doit se terminer.');
            self::assertSame($view['me'], $view['turn'], 'Les robots jouent jusqu’à mon tour.');

            if (0 === $actions % 40) {
                static::getContainer()->get('cache.rate_limiter')->clear();
            }

            $body = 'jeu' === $view['phase']
                ? $this->api('POST', $this->url($game).'/cartes', ['card' => $view['playable'][0]])
                : $this->api('POST', $this->url($game).'/encheres', ['take' => 'encheres-1' === $view['phase']]);

            self::assertResponseIsSuccessful();
            self::assertNotEmpty($body['events']);
            $view = $body['view'];
        }

        // Le tableau des scores : une ligne par donne, dont la somme donne le score final
        self::assertSame($view['scores'], [array_sum(array_column($view['dealScores'], 0)), array_sum(array_column($view['dealScores'], 1))]);
        self::assertSame(162, array_sum($view['points']), 'Les 162 points de la dernière donne.');

        $this->entityManager->clear();
        $saved = $this->entityManager->find(BeloteGame::class, $game->getId());
        $players = $this->entityManager->getRepository(GamePlayer::class)->findBy(['room' => $saved?->getRoom()]);

        self::assertNotNull($saved?->getWinnerTeam());
        self::assertSame(RoomStatus::Closed, $saved->getRoom()->getStatus());
        self::assertNotNull($saved->getRoom()->getFinishedAt());
        self::assertNotEmpty($this->entityManager->getRepository(BeloteDeal::class)->findBy(['game' => $saved]));
        self::assertSame(8 * \count($this->entityManager->getRepository(BeloteDeal::class)->findBy(['game' => $saved])), array_sum(array_map(static fn (GamePlayer $player): int => $player->getTricksWon(), $players)));
        self::assertCount(2, array_filter($players, static fn (GamePlayer $player): bool => true === $player->hasWon()));
    }

    public function testServerPlaysForThePlayerWhenTimeIsUp(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);
        $before = $this->api('GET', $this->url($game))['view'];
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE belote_game SET turn_started_at = :old WHERE id = :id',
            ['old' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), 'id' => $game->getId()],
        );

        $body = $this->api('POST', $this->url($game).'/temps-ecoule');

        self::assertResponseIsSuccessful();
        self::assertNotEmpty($body['events']);
        self::assertGreaterThan($before['version'], $body['view']['version']);
        self::assertSame($body['view']['me'], $body['view']['turn'], 'Les robots ont rejoué jusqu’à mon tour.');
        self::assertGreaterThan(14, $body['view']['secondsLeft']);
    }

    public function testTimeoutIsRefusedWhileTheTimeIsNotUp(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);

        $this->api('POST', $this->url($game).'/temps-ecoule');

        self::assertResponseStatusCodeSame(409);
    }

    public function testGamePageHasNoSiteHeader(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);
        $this->client->request('GET', '/jeux/belote/parties/'.$game->getRoom()->getId());

        // Pendant une partie, pas d'en-tête du site : ni menu, ni déconnexion, ni changement de profil
        self::assertSelectorNotExists('.site-header');
        self::assertSelectorNotExists('.account-menu');
        self::assertSelectorNotExists('form[action="/deconnexion"]');
        self::assertSelectorExists('dialog.belote__leave');
    }

    public function testLeavingHandsTheSeatToARobotForGood(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);

        $this->api('POST', $this->url($game).'/abandon');
        self::assertResponseStatusCodeSame(204);

        // Plus aucun joueur à la table : les robots ont fini la partie, comptée perdue pour celui qui est parti
        $this->entityManager->clear();
        $saved = $this->entityManager->find(BeloteGame::class, $game->getId()) ?? self::fail('Partie introuvable.');
        $players = $this->entityManager->getRepository(GamePlayer::class)->findBy(['room' => $saved->getRoom()]);
        $leaver = array_values(array_filter($players, static fn (GamePlayer $player): bool => $player->getUser()?->getId() === $user->getId()));

        self::assertCount(4, array_filter($saved->getRoom()->getSeats(), static fn ($seat): bool => $seat->isBot()));
        self::assertNotNull($saved->getWinnerTeam());
        self::assertSame(RoomStatus::Closed, $saved->getRoom()->getStatus());
        self::assertCount(1, $leaver);
        self::assertFalse($leaver[0]->hasWon());

        // Pas de retour possible à cette table
        $this->api('GET', $this->url($game));
        self::assertResponseStatusCodeSame(403);
    }

    public function testLeavingNeedsTheToken(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);
        $this->client->request('POST', $this->url($game).'/abandon', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(3, array_filter($game->getRoom()->getSeats(), static fn ($seat): bool => $seat->isBot()));
    }

    public function testWholeCoincheGameIsPlayedWithAnnouncements(): void
    {
        [$user, $game] = $this->gameAgainstBots(Game::Coinche);
        $this->client->loginUser($user);
        $view = $this->api('GET', $this->url($game))['view'];

        self::assertSame('coinche', $view['variant']);
        self::assertNull($view['turned']);
        self::assertCount(8, $view['hand']);

        for ($actions = 0; 'terminee' !== $view['phase']; ++$actions) {
            self::assertLessThan(600, $actions, 'La partie doit se terminer.');

            if (0 === $actions % 40) {
                static::getContainer()->get('cache.rate_limiter')->clear();
            }

            if ('annonces' === $view['phase']) {
                // J'annonce le plus petit contrat possible, sinon je passe
                $options = $view['auction'];
                $body = [] !== $options['contracts'] && null === $view['contract']
                    ? $this->api('POST', $this->url($game).'/annonce', ['contract' => $options['contracts'][0], 'suit' => 'coeur'])
                    : $this->api('POST', $this->url($game).'/encheres', ['take' => false]);
            } else {
                $body = $this->api('POST', $this->url($game).'/cartes', ['card' => $view['playable'][0]]);
            }

            self::assertResponseIsSuccessful();
            $view = $body['view'];
        }

        $this->entityManager->clear();
        $deals = $this->entityManager->getRepository(BeloteDeal::class)->findBy(['game' => $game->getId()]);
        self::assertNotEmpty($deals);
        self::assertNotNull($deals[0]->getContract());
    }

    public function testWholeGameWithAnnouncementsIDeclareMine(): void
    {
        [$user, $game] = $this->gameAgainstBots(Game::Belote, announcements: true);
        $this->client->loginUser($user);
        $view = $this->api('GET', $this->url($game))['view'];

        self::assertTrue($view['announcements']);

        for ($actions = 0; 'terminee' !== $view['phase']; ++$actions) {
            self::assertLessThan(800, $actions, 'La partie doit se terminer.');

            if (0 === $actions % 40) {
                static::getContainer()->get('cache.rate_limiter')->clear();
            }

            if ([] !== $view['declarable']) {
                $body = $this->api('POST', $this->url($game).'/declaration');
                self::assertSame('declared', $body['events'][0]['type']);
                self::assertSame([], $body['view']['declarable']);
            } elseif ('jeu' === $view['phase']) {
                $body = $this->api('POST', $this->url($game).'/cartes', ['card' => $view['playable'][0]]);
            } else {
                $body = $this->api('POST', $this->url($game).'/encheres', ['take' => true]);
            }

            self::assertResponseIsSuccessful();
            $view = $body['view'];
        }

        self::assertNotNull($view['winner']);
    }

    public function testDeclaringWithoutAnnouncementIsRefused(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);

        $this->api('POST', $this->url($game).'/declaration');

        self::assertResponseStatusCodeSame(409);
    }

    public function testAllTrumpsAndNoTrumpsOnlyAtTheCoinche(): void
    {
        [$user, $game] = $this->gameAgainstBots(Game::Coinche);
        $this->client->loginUser($user);
        $view = $this->api('GET', $this->url($game))['view'];

        if ('annonces' === $view['phase'] && [] !== $view['auction']['contracts']) {
            self::assertTrue($view['auction']['modes']);
            $body = $this->api('POST', $this->url($game).'/annonce', ['contract' => $view['auction']['contracts'][0], 'suit' => 'sans-atout']);
            self::assertResponseIsSuccessful();
            self::assertSame('sans-atout', $body['events'][0]['mode']);
        }

        [$contreeUser, $contree] = $this->gameAgainstBots(Game::Contree);
        $this->client->loginUser($contreeUser);
        $view = $this->api('GET', $this->url($contree))['view'];

        if ('annonces' === $view['phase'] && [] !== $view['auction']['contracts']) {
            self::assertFalse($view['auction']['modes']);
            $this->api('POST', $this->url($contree).'/annonce', ['contract' => $view['auction']['contracts'][0], 'suit' => 'tout-atout']);
            self::assertResponseStatusCodeSame(409);
        }
    }

    public function testAnnouncementMustBeHigherAndValid(): void
    {
        [$user, $game] = $this->gameAgainstBots(Game::Coinche);
        $this->client->loginUser($user);
        $view = $this->api('GET', $this->url($game))['view'];

        $body = $this->api('POST', $this->url($game).'/annonce', ['contract' => 85, 'suit' => 'coeur']);
        self::assertResponseStatusCodeSame(422);

        if (null !== $view['contract'] && 160 !== $view['contract']) {
            $this->api('POST', $this->url($game).'/annonce', ['contract' => $view['contract'], 'suit' => 'coeur']);
            self::assertResponseStatusCodeSame(409);
        }

        // Pas d'annonce en belote classique
        [$classicUser, $classic] = $this->gameAgainstBots();
        $this->client->loginUser($classicUser);
        $this->api('POST', $this->url($classic).'/annonce', ['contract' => 80, 'suit' => 'coeur']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testRefusedMoveExplainsWhyAndReturnsTheView(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);
        $view = $this->api('GET', $this->url($game))['view'];

        // Les robots placés avant moi ont pu prendre : selon la donne, on est encore aux enchères ou déjà en jeu
        $bidding = 'jeu' !== $view['phase'];
        $refused = array_values(array_diff($view['hand'], $view['playable']));
        $card = $bidding ? $view['hand'][0] : ($refused[0] ?? null);

        if (null === $card) {
            self::markTestSkipped('Toutes mes cartes sont jouables dans cette donne : aucun coup à refuser.');
        }

        $body = $this->api('POST', $this->url($game).'/cartes', ['card' => $card]);

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        // Pendant les enchères, on ne joue pas de carte ; en jeu, il faut suivre les règles
        self::assertStringContainsString($bidding ? 'enchères' : 'Vous devez', $body['detail']);
        self::assertSame($view['version'], $body['view']['version']);
    }

    public function testSurcoincheWithoutCoincheIsRefused(): void
    {
        [$user, $game] = $this->gameAgainstBots(Game::Coinche);
        $this->client->loginUser($user);

        // Personne n'a coinché : surcoincher est refusé, avec la vue à jour
        $body = $this->api('POST', $this->url($game).'/surcoinche', []);

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertNotEmpty($body['detail']);
        self::assertArrayHasKey('view', $body);
    }

    public function testUnknownCardIsRefused(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);

        $this->api('POST', $this->url($game).'/cartes', ['card' => 'coeur-joker']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testOnlyPlayersAtTheTableSeeTheGame(): void
    {
        [, $game] = $this->gameAgainstBots();
        $this->client->loginUser($this->createUser('Curieux'));

        $this->api('GET', $this->url($game));

        self::assertResponseStatusCodeSame(403);
    }

    public function testMoveFromAnotherSiteIsRefused(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);

        $this->client->request('POST', $this->url($game).'/encheres', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
            'HTTP_ORIGIN' => 'https://pirate.example',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ], json_encode(['take' => false], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(403);
    }

    public function testRoomWithOnlyBotsLeftIsClosed(): void
    {
        $user = $this->createUser('Fred');
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($user, Game::Belote, 501, false);
        $manager->addBot($user, $room, 2);

        $manager->leave($user, $room);

        self::assertSame(RoomStatus::Closed, $room->getStatus());
    }

    /**
     * @return array{User, BeloteGame}
     */
    private function gameAgainstBots(Game $game = Game::Belote, bool $announcements = false): array
    {
        $user = $this->createUser('Fred');
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($user, $game, $game->defaultTargetPoints(), $announcements);

        foreach ([2, 3, 4] as $position) {
            $manager->addBot($user, $room, $position);
        }

        $game = $this->entityManager->getRepository(BeloteGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');

        return [$user, $game];
    }

    private function url(BeloteGame $game): string
    {
        return '/api/v1/jeux/belote/parties/'.$game->getId();
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

        foreach (['belote_deal', 'belote_game', 'game_player', 'game_room_seat', 'game_room', 'reset_password_request', 'profile', '`user`'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
    }
}
