<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\PatienceGame;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\Game;
use App\Service\GameRoomManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FreeCell, Pyramide et Golf solitaire : duels solo (la même donne pour tous, 5 minutes), une API commune.
 * Chaque route, pour chacun des trois jeux.
 */
final class CardPatienceApiControllerTest extends WebTestCase
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

    /**
     * @return iterable<string, array{Game}>
     */
    public static function games(): iterable
    {
        yield 'FreeCell' => [Game::Freecell];
        yield 'Pyramide' => [Game::Pyramide];
        yield 'Golf solitaire' => [Game::GolfSolitaire];
    }

    #[DataProvider('games')]
    public function testTheTableAndTheLobbyOfEachGame(Game $game): void
    {
        [$user, $patience] = $this->duel($game);
        $this->client->loginUser($user);

        $this->client->request('GET', '/jeux/'.$game->value.'/parties/'.$patience->getRoom()->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main#partie.patience--'.$game->value);
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('h1', $game->label());
        self::assertSelectorTextContains('.belote__rules-title', $game->label());

        $this->client->request('GET', '/jeux/'.$game->value);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#regles .rules__step');

        $this->client->request('GET', '/jeux');
        self::assertSelectorExists('a.game__play[href="/jeux/'.$game->value.'"]');
    }

    #[DataProvider('games')]
    public function testAPlayableCardIsPlayedThenTakenBack(Game $game): void
    {
        [$user, $patience] = $this->duel($game);
        $this->client->loginUser($user);

        $view = $this->api('GET', $this->url($patience))['view'];
        self::assertResponseIsSuccessful();
        self::assertSame('jeu', $view['phase']);
        self::assertSame(0, $view['board']['score']);
        self::assertSame(Game::Freecell === $game ? 52 : (Game::Pyramide === $game ? 28 : 35), $view['board']['cardCount']);

        // Pas de coup jouable tout de suite (Pyramide, Golf) : on retourne le talon jusqu'à en trouver un
        for ($turn = 0; $turn < 24 && [] === $view['board']['targets'] && ($view['board']['canDraw'] ?? false); ++$turn) {
            $view = $this->api('POST', $this->url($patience).'/talon')['view'];
            self::assertResponseIsSuccessful();
        }

        self::assertNotEmpty($view['board']['targets'], 'Aucun coup trouvé');
        $card = array_key_first($view['board']['targets']);
        $view = $this->api('POST', $this->url($patience).'/deplacements', ['card' => $card, 'to' => $view['board']['targets'][$card][0]])['view'];
        self::assertResponseIsSuccessful();
        self::assertTrue($view['board']['canUndo']);

        $this->api('POST', $this->url($patience).'/retour');
        self::assertResponseIsSuccessful();

        // « Arrêter » : la grille est finie, le duel attend les autres joueurs
        $view = $this->api('POST', $this->url($patience).'/arret')['view'];
        self::assertSame('attente', $view['phase']);
        self::assertNotEmpty($view['board']['details']);
    }

    public function testRefusedMovesAndWrongAddresses(): void
    {
        [$user, $patience] = $this->duel(Game::Freecell);
        $this->client->loginUser($user);

        $problem = $this->api('POST', $this->url($patience).'/deplacements', ['card' => 'coeur-roi', 'to' => 'famille']);
        self::assertResponseStatusCodeSame(409);
        self::assertArrayHasKey('view', $problem);

        $this->api('POST', $this->url($patience).'/deplacements', ['card' => 'coeur-roi', 'to' => 'ailleurs']);
        self::assertResponseStatusCodeSame(422);

        $this->api('POST', $this->url($patience).'/talon');
        self::assertResponseStatusCodeSame(409);

        // Une partie de FreeCell n'existe pas à l'adresse de la Pyramide
        $this->api('GET', '/api/v1/jeux/pyramide/parties/'.$patience->getId());
        self::assertResponseStatusCodeSame(404);

        // Un joueur qui n'est pas à la table
        $this->client->loginUser($this->createUser('Lea'));
        $this->api('GET', $this->url($patience));
        self::assertResponseStatusCodeSame(403);
    }

    public function testLeavingClosesMyBoard(): void
    {
        [$user, $patience] = $this->duel(Game::GolfSolitaire);
        $this->client->loginUser($user);

        $this->client->request('POST', $this->url($patience).'/abandon', [], [], ['HTTP_X_CSRF_TOKEN' => 'csrf-token']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('attente', $this->api('GET', $this->url($patience))['view']['phase']);
    }

    public function testVisitorsMustLogIn(): void
    {
        [, $patience] = $this->duel(Game::Pyramide);

        $this->api('GET', $this->url($patience));
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @return array{User, PatienceGame}
     */
    private function duel(Game $game): array
    {
        $user = $this->createUser('Fred');
        $room = static::getContainer()->get(GameRoomManager::class)->create($user, $game, 1, false, 2);
        $patience = $this->entityManager->getRepository(PatienceGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');

        return [$user, $patience];
    }

    private function url(PatienceGame $game): string
    {
        return '/api/v1/jeux/'.$game->getRoom()->getGame()->value.'/parties/'.$game->getId();
    }

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
