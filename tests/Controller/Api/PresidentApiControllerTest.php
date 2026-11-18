<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\GameRoom;
use App\Entity\PresidentGame;
use App\Entity\PresidentRound;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\Game;
use App\Enum\RoomStatus;
use App\Game\President\PresidentCard;
use App\Service\GameRoomManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Partie de Président contre des robots (PLAYORA_BOTS=1 dans .env.test) : chaque route de l'API.
 * Les robots jouent aussitôt après chaque coup : c'est toujours au joueur de jouer quand il reçoit sa vue.
 */
final class PresidentApiControllerTest extends WebTestCase
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

    public function testTheGameStartsWithAllTheCardsDealtAndItIsMyTurn(): void
    {
        [$user, $game] = $this->gameAgainstBots(4);
        $this->client->loginUser($user);

        $view = $this->api('GET', $this->url($game))['view'];

        self::assertSame(RoomStatus::Playing, $game->getRoom()->getStatus());
        self::assertSame('jeu', $view['phase']);
        self::assertSame($view['me'], $view['turn']);
        self::assertCount(4, $view['players']);
        self::assertSame(3, $view['target']);
        self::assertSame(12, $view['goal']);
        self::assertLessThanOrEqual(52, array_sum(array_column($view['players'], 'cards')));
        self::assertCount($view['players'][$view['me']]['cards'], $view['hand']);
        // Des autres joueurs, seulement le nombre de cartes ; ce que je peux poser vient du serveur
        self::assertArrayNotHasKey('hands', $view);
        self::assertSame([], array_diff($view['options']['ranks'], array_map(static fn (string $code): string => PresidentCard::fromCode($code)->rank, $view['hand'])));

        $this->client->request('GET', '/jeux/president/parties/'.$game->getRoom()->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main#partie.president');
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('h1', 'Président');

        [$user, $game] = $this->gameAgainstBots(7, pseudo: 'Léa');
        $this->client->loginUser($user);
        $view = $this->api('GET', $this->url($game))['view'];
        self::assertCount(7, $view['players']);
        self::assertSame(1, $view['round']);
    }

    public function testICanPlayTheCardsIAmAllowedToOrPass(): void
    {
        [$user, $game] = $this->gameAgainstBots(4);
        $this->client->loginUser($user);
        $view = $this->api('GET', $this->url($game))['view'];
        $version = $view['version'];

        if ([] === $view['options']['ranks']) {
            $view = $this->api('POST', $this->url($game).'/passe')['view'];
        } else {
            $rank = $view['options']['ranks'][0];
            $cards = array_values(array_filter($view['hand'], static fn (string $code): bool => PresidentCard::fromCode($code)->rank === $rank));
            $view = $this->api('POST', $this->url($game).'/cartes', ['cards' => \array_slice($cards, 0, $view['options']['count'] ?? \count($cards))])['view'];
        }

        self::assertResponseIsSuccessful();
        self::assertGreaterThan($version, $view['version']);
    }

    public function testRefusedMovesKeepTheGameAsItIs(): void
    {
        [$user, $game] = $this->gameAgainstBots(3);
        $this->client->loginUser($user);
        $view = $this->api('GET', $this->url($game))['view'];

        $missing = array_values(array_diff(array_map(static fn (PresidentCard $card): string => $card->code(), PresidentCard::deck()), $view['hand']));
        $body = $this->api('POST', $this->url($game).'/cartes', ['cards' => [$missing[0]]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Cette carte n’est pas dans votre main.', $body['detail']);
        self::assertSame($view['version'], $body['view']['version']);

        $body = $this->api('POST', $this->url($game).'/echange', ['cards' => [$view['hand'][0]]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('L’échange des cartes est terminé.', $body['detail']);

        // Deux valeurs différentes ne se posent pas ensemble
        $ranks = array_unique(array_map(static fn (string $code): string => PresidentCard::fromCode($code)->rank, $view['hand']));

        if (\count($ranks) > 1 && null === $view['options']['count']) {
            $first = $view['hand'][0];
            $other = array_values(array_filter($view['hand'], static fn (string $code): bool => PresidentCard::fromCode($code)->rank !== PresidentCard::fromCode($first)->rank))[0];
            $body = $this->api('POST', $this->url($game).'/cartes', ['cards' => [$first, $other]]);
            self::assertResponseStatusCodeSame(409);
            self::assertSame('Posez des cartes de même valeur.', $body['detail']);
        }
    }

    public function testInvalidRequestsAreRejectedBeforeReachingTheGame(): void
    {
        [$user, $game] = $this->gameAgainstBots(3);
        $this->client->loginUser($user);

        $this->api('POST', $this->url($game).'/cartes', ['cards' => ['coeur-1']]);
        self::assertResponseStatusCodeSame(422);

        $this->api('POST', $this->url($game).'/cartes', ['cards' => []]);
        self::assertResponseStatusCodeSame(422);

        $this->api('POST', $this->url($game).'/cartes', ['cards' => ['coeur-3', 'pique-3', 'carreau-3', 'trefle-3', 'coeur-4']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testTimeIsUpOnlyAfterTheTurnTime(): void
    {
        [$user, $game] = $this->gameAgainstBots(3);
        $this->client->loginUser($user);

        $body = $this->api('POST', $this->url($game).'/temps-ecoule');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Le temps n’est pas encore écoulé.', $body['detail']);

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE president_game SET turn_started_at = :start WHERE id = :id',
            ['start' => (new \DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s'), 'id' => $game->getId()],
        );
        $version = $this->api('GET', $this->url($game))['view']['version'];
        $view = $this->api('POST', $this->url($game).'/temps-ecoule')['view'];

        // Le serveur a joué pour moi (des cartes, ou il a passé) : la partie a avancé
        self::assertResponseIsSuccessful();
        self::assertGreaterThan($version, $view['version']);
    }

    public function testWhenTheLastPlayerLeavesTheRobotsFinishTheGame(): void
    {
        [$user, $game] = $this->gameAgainstBots(4, 2);
        $this->client->loginUser($user);

        $this->client->request('POST', $this->url($game).'/abandon', [], [], ['HTTP_X_CSRF_TOKEN' => 'csrf-token']);
        self::assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $room = $this->entityManager->find(GameRoom::class, $game->getRoom()->getId()) ?? self::fail('Salon introuvable.');
        self::assertSame(RoomStatus::Closed, $room->getStatus());
        $rounds = $this->entityManager->getRepository(PresidentRound::class)->findBy(['game' => $game->getId()]);
        self::assertNotEmpty($rounds);
        self::assertCount(4, $rounds[0]->getOrder());
        self::assertSame([0, 1, 2, 3], $this->sorted($rounds[0]->getPoints()));
        $final = $this->entityManager->find(PresidentGame::class, $game->getId()) ?? self::fail('Partie introuvable.');
        self::assertGreaterThanOrEqual(8, max($final->getScores()));
        self::assertNotNull($final->getWinnerPosition());

        // Il n'est plus à la table
        $this->api('GET', $this->url($game));
        self::assertResponseStatusCodeSame(403);
    }

    public function testOnlyThePlayersSeeTheGame(): void
    {
        [, $game] = $this->gameAgainstBots(3);

        $this->client->request('GET', $this->url($game));
        self::assertResponseStatusCodeSame(401);

        $this->client->loginUser($this->createUser('Curieux'));
        $this->api('GET', $this->url($game));
        self::assertResponseStatusCodeSame(403);

        $this->api('GET', '/api/v1/jeux/president/parties/999999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testActionsNeedTheFormToken(): void
    {
        [$user, $game] = $this->gameAgainstBots(3);
        $this->client->loginUser($user);

        $this->client->request('POST', $this->url($game).'/passe', [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param list<int> $values
     *
     * @return list<int>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * @return array{User, PresidentGame}
     */
    private function gameAgainstBots(int $players, int $target = 3, string $pseudo = 'Fred'): array
    {
        $user = $this->createUser($pseudo);
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($user, Game::President, $target, false, $players);

        foreach (range(2, $players) as $position) {
            $manager->addBot($user, $room, $position);
        }

        $game = $this->entityManager->getRepository(PresidentGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');

        return [$user, $game];
    }

    private function url(PresidentGame $game): string
    {
        return '/api/v1/jeux/president/parties/'.$game->getId();
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

        foreach (['president_round', 'president_game', 'huit_round', 'huit_game', 'rami_round', 'rami_game', 'solitaire_board', 'solitaire_game', 'tarot_deal', 'tarot_game', 'belote_deal', 'belote_game', 'game_player', 'game_room_seat', 'game_room', 'reset_password_request', 'profile', '`user`'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
    }
}
