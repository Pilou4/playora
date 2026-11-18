<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\PatienceBoard;
use App\Entity\PatienceGame;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\Game;
use App\Enum\RoomStatus;
use App\Service\GameRoomManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Duel de Spider, chacun à son rythme et sans robots : chaque route de l'API.
 */
final class SpiderApiControllerTest extends WebTestCase
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

    public function testTheGameStartsWithTheSameDealForEveryone(): void
    {
        [$user, $game] = $this->duel(Game::Spider, 3, ['Bob', 'Léa']);
        $boards = $this->entityManager->getRepository(PatienceBoard::class)->findBy(['game' => $game]);

        // Toutes les places prises : le salon quitte la liste
        self::assertSame(RoomStatus::Playing, $game->getRoom()->getStatus());
        self::assertCount(3, $boards);
        self::assertCount(1, array_unique(array_map(static fn (PatienceBoard $board): int => $board->getStateData()['seed'], $boards)));

        $this->client->loginUser($user);
        $this->client->request('GET', '/jeux/spider/parties/'.$game->getRoom()->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main#partie.spider');
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('h1', 'Spider');
        self::assertSelectorCount(10, '.spider__tableau .solitaire__column');
        self::assertSelectorCount(1, '.spider__joker');
        self::assertSelectorTextContains('.solitaire__undo-cost', '50');
    }

    public function testMyViewHidesFaceDownCards(): void
    {
        [$user, $game] = $this->duel(Game::Spider);
        $this->client->loginUser($user);

        $view = $this->api('GET', $this->url($game))['view'];
        $cards = array_merge(...$view['board']['columns']);

        self::assertSame('jeu', $view['phase']);
        self::assertSame('classique', $view['board']['level']);
        self::assertCount(10, $view['board']['columns']);
        // 54 cartes sur les piles, dont une visible par pile
        self::assertCount(54, $cards);
        self::assertCount(44, array_filter($cards, static fn (?string $card): bool => null === $card));
        // 5 piles talon de 10 cartes : seule la première se voit
        self::assertCount(5, $view['board']['stock']);
        self::assertSame(10, $view['board']['stock'][0]['count']);
        self::assertCount(1, $view['board']['stock'][0]['shown']);
        self::assertSame([null], $view['board']['jokers']);
        self::assertNotEmpty($view['board']['targets']);
        self::assertArrayNotHasKey('history', $view['board']);
    }

    public function testProShowsTheWholeStockPileAndHasTwoJokers(): void
    {
        [$user, $game] = $this->duel(Game::SpiderPro);
        $this->client->loginUser($user);

        $board = $this->api('GET', $this->url($game))['view']['board'];

        self::assertSame('pro', $board['level']);
        self::assertCount(10, $board['stock'][0]['shown']);
        self::assertSame([null, null], $board['jokers']);
        self::assertSame(500, $board['undoCost']);
    }

    public function testMovingCardsAndDealingAStockPile(): void
    {
        [$user, $game] = $this->duel(Game::SpiderBeginner);
        $this->startNow($game);
        $this->client->loginUser($user);

        $view = $this->api('GET', $this->url($game))['view'];
        self::assertSame('jeu', $view['phase']);

        // Chaque place annoncée par la vue est acceptée par le serveur
        $card = array_key_first($view['board']['targets']);
        $view = $this->api('POST', $this->url($game).'/deplacements', ['card' => $card, 'to' => $view['board']['targets'][$card][0]])['view'];
        self::assertResponseIsSuccessful();

        // Une carte encore dans le talon ne se prend pas
        $body = $this->api('POST', $this->url($game).'/deplacements', ['card' => $view['board']['stock'][1]['shown'][0], 'to' => 'colonne-0']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Cette carte ne peut pas être prise.', $body['detail']);
        self::assertArrayHasKey('view', $body);

        // Distribuer une pile talon : une carte de plus sur chaque pile (si aucune n'est vide)
        $before = array_map('count', $view['board']['columns']);
        $view = $this->api('POST', $this->url($game).'/talon', ['pile' => 0])['view'];

        if (\in_array(0, $before, true)) {
            self::assertResponseStatusCodeSame(409);
        } else {
            self::assertResponseIsSuccessful();
            self::assertSame(0, $view['board']['stock'][0]['count']);
            self::assertSame(array_sum($before) + 10, array_sum(array_map('count', $view['board']['columns'])));

            $this->api('POST', $this->url($game).'/talon', ['pile' => 0]);
            self::assertResponseStatusCodeSame(409);
        }
    }

    public function testInvalidRequestsAreRejectedBeforeReachingTheGame(): void
    {
        [$user, $game] = $this->duel(Game::Spider);
        $this->startNow($game);
        $this->client->loginUser($user);

        $this->api('POST', $this->url($game).'/deplacements', ['card' => 'pique-roi', 'to' => 'colonne-0']);
        self::assertResponseStatusCodeSame(422);

        $this->api('POST', $this->url($game).'/deplacements', ['card' => 'pique-roi-1', 'to' => 'famille']);
        self::assertResponseStatusCodeSame(422);

        $this->api('POST', $this->url($game).'/talon', ['pile' => 7]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testNothingToUndoAtTheStart(): void
    {
        [$user, $game] = $this->duel(Game::Spider);
        $this->startNow($game);
        $this->client->loginUser($user);

        $body = $this->api('POST', $this->url($game).'/retour');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Rien à annuler.', $body['detail']);
        self::assertFalse($body['view']['board']['canUndo']);
    }

    public function testStoppingEndsTheGame(): void
    {
        [$user, $game] = $this->duel(Game::Spider);
        $this->startNow($game);
        $this->client->loginUser($user);

        $view = $this->api('POST', $this->url($game).'/arret')['view'];

        // Ma partie est finie : mon résultat, en attendant un autre joueur à la place libre
        self::assertResponseIsSuccessful();
        self::assertSame('attente', $view['phase']);
        self::assertCount(4, $view['board']['details']);
        self::assertTrue($view['players'][1 - $view['me']]['free']);

        $this->api('POST', $this->url($game).'/deplacements', ['card' => 'pique-roi-1', 'to' => 'colonne-0']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testTimeIsUpOnlyAfterFiveMinutes(): void
    {
        [$user, $game] = $this->duel(Game::Spider);
        $this->startNow($game);
        $this->client->loginUser($user);

        $body = $this->api('POST', $this->url($game).'/temps-ecoule');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Le temps n’est pas encore écoulé.', $body['detail']);

        $this->startNow($game, PatienceBoard::SECONDS + 5);
        $view = $this->api('POST', $this->url($game).'/temps-ecoule')['view'];

        self::assertResponseIsSuccessful();
        self::assertSame('attente', $view['phase']);
    }

    public function testLeavingClosesMyGameAndTheDuelGoesOn(): void
    {
        [$user, $game] = $this->duel(Game::Spider, 3, ['Bob']);
        $this->client->loginUser($user);

        $this->client->request('POST', $this->url($game).'/abandon', [], [], ['HTTP_X_CSRF_TOKEN' => 'csrf-token']);
        self::assertResponseStatusCodeSame(204);

        // Ma grille est close ; Bob joue encore et une place reste libre : le duel continue
        $this->entityManager->clear();
        self::assertFalse($this->entityManager->find(PatienceGame::class, $game->getId())?->isFinished());
        self::assertSame('attente', $this->api('GET', $this->url($game))['view']['phase']);
    }

    public function testASolitaireGameIsNotFoundHere(): void
    {
        $user = $this->createUser('Fred');
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($user, Game::Solitaire, 1, false);
        $solitaire = $this->entityManager->getRepository(PatienceGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');

        $this->client->loginUser($user);
        $this->api('GET', $this->url($solitaire));
        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyThePlayersSeeTheGame(): void
    {
        [, $game] = $this->duel(Game::Spider);

        $this->client->request('GET', $this->url($game));
        self::assertResponseStatusCodeSame(401);

        $this->client->loginUser($this->createUser('Curieux'));
        $this->api('GET', $this->url($game));
        self::assertResponseStatusCodeSame(403);
    }

    public function testActionsNeedTheFormToken(): void
    {
        [$user, $game] = $this->duel(Game::Spider);
        $this->startNow($game);
        $this->client->loginUser($user);

        $this->client->request('POST', $this->url($game).'/talon', [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], '{"pile":0}');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Fred crée un duel (sa partie commence aussitôt) ; les joueurs nommés le rejoignent.
     *
     * @param list<string> $joiners
     *
     * @return array{User, PatienceGame}
     */
    private function duel(Game $spider, int $players = 2, array $joiners = []): array
    {
        $user = $this->createUser('Fred');
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($user, $spider, 1, false, $players);

        foreach ($joiners as $pseudo) {
            $manager->join($this->createUser($pseudo), $room);
        }

        $game = $this->entityManager->getRepository(PatienceGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');

        return [$user, $game];
    }

    /**
     * Les grilles ont commencé il y a $secondsAgo secondes (au lieu d'attendre le compte à rebours).
     */
    private function startNow(PatienceGame $game, int $secondsAgo = 1): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE solitaire_board SET started_at = :start WHERE game_id = :game',
            ['start' => (new \DateTimeImmutable(\sprintf('-%d seconds', $secondsAgo)))->format('Y-m-d H:i:s'), 'game' => $game->getId()],
        );
    }

    private function url(PatienceGame $game): string
    {
        return '/api/v1/jeux/spider/parties/'.$game->getId();
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

        foreach (['solitaire_board', 'solitaire_game', 'tarot_deal', 'tarot_game', 'belote_deal', 'belote_game', 'game_player', 'game_room_seat', 'game_room', 'reset_password_request', 'profile', '`user`'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
    }
}
