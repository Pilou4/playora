<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\GamePlayer;
use App\Entity\Profile;
use App\Entity\TarotDeal;
use App\Entity\TarotGame;
use App\Entity\User;
use App\Enum\CardSet;
use App\Enum\Game;
use App\Enum\RoomStatus;
use App\Service\GameRoomManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Parties de tarot contre 3 robots (PLAYORA_BOTS=1 dans .env.test).
 */
final class TarotApiControllerTest extends WebTestCase
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

    public function testTheTableOpensWithTheTarotCards(): void
    {
        [$user, $game] = $this->gameAgainstBots();

        self::assertSame(RoomStatus::Playing, $game->getRoom()->getStatus());
        self::assertSame(CardSet::TarotNouveau, $game->getRoom()->getCardSet());
        self::assertCount(4, $this->entityManager->getRepository(GamePlayer::class)->findBy(['room' => $game->getRoom()]));

        $this->client->loginUser($user);
        $this->client->request('GET', '/jeux/tarot/parties/'.$game->getRoom()->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main#partie.tarot');
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('h1', 'tarot');
    }

    public function testMyViewShowsOnlyMyHand(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);

        $view = $this->api('GET', $this->url($game))['view'];
        $state = $game->getState()->toArray();

        self::assertSame($state['hands'][$view['me']], $view['hand']);
        self::assertArrayNotHasKey('hands', $view);
        self::assertSame(4, $view['deals']);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function playerCounts(): iterable
    {
        yield '3 joueurs' => [3];
        yield '4 joueurs' => [4];
        yield '5 joueurs' => [5];
    }

    #[DataProvider('playerCounts')]
    public function testWholeGameAgainstBots(int $players): void
    {
        [$user, $game] = $this->gameAgainstBots($players);
        $this->client->loginUser($user);
        $view = $this->api('GET', $this->url($game))['view'];

        for ($actions = 0; 'terminee' !== $view['phase']; ++$actions) {
            self::assertLessThan(500, $actions, 'La partie doit se terminer.');

            if (0 === $actions % 40) {
                static::getContainer()->get('cache.rate_limiter')->clear();
            }

            $body = match (true) {
                // J'annonce une garde si personne n'a pris, sinon je passe
                'encheres' === $view['phase'] => $this->api('POST', $this->url($game).'/encheres', ['contract' => \in_array('garde', $view['contracts'], true) && null === $view['contract'] ? 'garde' : null]),
                'appel' === $view['phase'] => $this->api('POST', $this->url($game).'/appel', ['card' => $view['callable'][0]]),
                'chien' === $view['phase'] => $this->api('POST', $this->url($game).'/ecart', ['cards' => \array_slice($view['discardable'], 0, $view['dogSize'])]),
                [] !== $view['poigneesPossible'] => $this->api('POST', $this->url($game).'/poignee', ['poignee' => $view['poigneesPossible'][0]]),
                default => $this->api('POST', $this->url($game).'/cartes', ['card' => $view['playable'][0]]),
            };

            self::assertResponseIsSuccessful();
            $view = $body['view'];
        }

        self::assertSame(0, array_sum($view['scores']));
        self::assertCount($players, $view['scores']);
        self::assertNotNull($view['winner']);

        $this->entityManager->clear();
        $deals = $this->entityManager->getRepository(TarotDeal::class)->findBy(['game' => $game->getId()]);
        self::assertCount(4, $deals);
    }

    public function testRefusedMovesExplainWhy(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);
        $view = $this->api('GET', $this->url($game))['view'];

        // Une carte qui n'est pas dans ma main
        $mine = $view['hand'];
        $other = 'excuse' === ($mine[0] ?? '') || \in_array('excuse', $mine, true) ? 'atout-21' : 'excuse';
        $other = \in_array($other, $mine, true) ? 'pique-1' : $other;
        $body = $this->api('POST', $this->url($game).'/cartes', ['card' => $other]);

        self::assertResponseStatusCodeSame(409);
        self::assertArrayHasKey('view', $body);

        $this->api('POST', $this->url($game).'/encheres', ['contract' => 'garde-tres-forte']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testOnlyTheTakerAnnouncesAChelem(): void
    {
        [$user, $game] = $this->gameAgainstBots();
        $this->client->loginUser($user);

        // Aux enchères, il n'y a pas encore de preneur
        $body = $this->api('POST', $this->url($game).'/chelem', []);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Seul le preneur annonce le chelem, avant la première carte.', $body['detail']);
        self::assertArrayHasKey('view', $body);
    }

    public function testOnlyThePlayersOfTheTableSeeIt(): void
    {
        [, $game] = $this->gameAgainstBots();
        $this->client->loginUser($this->createUser('Intrus'));

        $this->api('GET', $this->url($game));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array{User, TarotGame}
     */
    private function gameAgainstBots(int $players = 4): array
    {
        $user = $this->createUser('Fred');
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($user, Game::Tarot, 4, false, $players);

        foreach (range(2, $players) as $position) {
            $manager->addBot($user, $room, $position);
        }

        $game = $this->entityManager->getRepository(TarotGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');

        return [$user, $game];
    }

    private function url(TarotGame $game): string
    {
        return '/api/v1/jeux/tarot/parties/'.$game->getId();
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

        foreach (['tarot_deal', 'tarot_game', 'belote_deal', 'belote_game', 'game_player', 'game_room_seat', 'game_room', 'reset_password_request', 'profile', '`user`'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
    }
}
