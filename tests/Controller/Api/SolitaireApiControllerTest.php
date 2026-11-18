<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\GamePlayer;
use App\Entity\Profile;
use App\Entity\PatienceBoard;
use App\Entity\PatienceGame;
use App\Entity\User;
use App\Enum\Game;
use App\Enum\RoomStatus;
use App\Game\Solitaire\SolitaireScore;
use App\Service\GameRoomManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Duel de solitaire, chacun à son rythme et sans robots : chaque route de l'API.
 * Le créateur joue dès la création du salon ; chaque joueur qui rejoint joue aussitôt la même donne.
 */
final class SolitaireApiControllerTest extends WebTestCase
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

    public function testTheCreatorPlaysAtOnceAndTheRoomStaysOpen(): void
    {
        [$user, $game] = $this->duel();
        $boards = $this->entityManager->getRepository(PatienceBoard::class)->findBy(['game' => $game]);

        // Une seule grille (la sienne), le salon attend d'autres joueurs
        self::assertSame(RoomStatus::Waiting, $game->getRoom()->getStatus());
        self::assertCount(1, $boards);
        self::assertCount(1, $this->entityManager->getRepository(GamePlayer::class)->findBy(['room' => $game->getRoom()]));

        $this->client->loginUser($user);
        $this->client->request('GET', '/jeux/solitaire/parties/'.$game->getRoom()->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main#partie.solitaire');
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('h1', 'solitaire');
        // Plus de panneau des joueurs pendant la partie : seulement son score
        self::assertSelectorNotExists('.solitaire__duel');
    }

    public function testAJoiningPlayerGetsTheSameDeal(): void
    {
        [, $game] = $this->duel(3);
        $bob = $this->createUser('Bob');
        $manager = static::getContainer()->get(GameRoomManager::class);
        $manager->join($bob, $game->getRoom());

        $boards = $this->entityManager->getRepository(PatienceBoard::class)->findBy(['game' => $game]);
        self::assertCount(2, $boards);
        self::assertSame($boards[0]->getStateData()['seed'], $boards[1]->getStateData()['seed']);
        // Une place libre : le salon reste dans la liste
        self::assertSame(RoomStatus::Waiting, $game->getRoom()->getStatus());

        $manager->join($this->createUser('Léa'), $game->getRoom());
        self::assertSame(RoomStatus::Playing, $game->getRoom()->getStatus());
    }

    public function testMyViewHidesFaceDownCardsAndTheOthersScores(): void
    {
        [$user, $game] = $this->duel();
        $this->client->loginUser($user);

        $view = $this->api('GET', $this->url($game))['view'];

        // Pas de compte à rebours : la partie commence dès la création
        self::assertSame('jeu', $view['phase']);
        self::assertSame(SolitaireScore::SECONDS, $view['seconds']);
        self::assertSame(24, $view['board']['stock']);
        self::assertNull($view['board']['tableau'][6][0]);
        self::assertNotEmpty($view['board']['targets']);
        self::assertArrayNotHasKey('history', $view['board']);
        self::assertSame([], $view['board']['details']);
        // L'autre place : libre, sans score
        $other = $view['players'][1 - $view['me']];
        self::assertTrue($other['free']);
        self::assertNull($other['score']);
    }

    public function testDrawingThreeCardsAndMovingCards(): void
    {
        [$user, $game] = $this->duel();
        $this->startNow($game);
        $this->client->loginUser($user);

        // Sans rangement automatique : les 3 cartes retournées restent visibles
        $view = $this->api('POST', $this->url($game).'/talon', ['auto' => false])['view'];
        self::assertResponseIsSuccessful();
        self::assertSame('jeu', $view['phase']);
        // Solitaire : 3 cartes à la fois
        self::assertSame(21, $view['board']['stock']);
        self::assertCount(3, $view['board']['waste']);
        // Encore des cartes à piocher : le talon se clique
        self::assertFalse($view['board']['lastCards']);
        // Rien sous les 3 premières cartes retournées
        self::assertNull($view['board']['under']);
        self::assertTrue($view['board']['canUndo']);

        // Chaque place annoncée par la vue est acceptée par le serveur
        $card = array_key_first($view['board']['targets']);
        $to = $view['board']['targets'][$card][0];
        $this->api('POST', $this->url($game).'/deplacements', ['card' => $card, 'to' => $to]);
        self::assertResponseIsSuccessful();

        // Un coup interdit est refusé avec un message pour le joueur
        $body = $this->api('POST', $this->url($game).'/deplacements', ['card' => 'coeur-roi', 'to' => 'famille']);
        self::assertResponseStatusCodeSame(409);
        self::assertNotEmpty($body['detail']);
        self::assertArrayHasKey('view', $body);
    }

    public function testSolitaireHarmonieDrawsOneCard(): void
    {
        [$user, $game] = $this->duel(game: Game::SolitaireHarmonie);
        $this->startNow($game);
        $this->client->loginUser($user);

        $view = $this->api('POST', $this->url($game).'/talon', ['auto' => false])['view'];

        self::assertResponseIsSuccessful();
        self::assertSame(23, $view['board']['stock']);
        self::assertCount(1, $view['board']['waste']);
        self::assertNull($view['board']['under']);

        // Deuxième carte : la première est juste dessous, déjà affichée (on la voit dès qu'on prend celle du dessus)
        $first = $view['board']['waste'][0];
        $view = $this->api('POST', $this->url($game).'/talon', ['auto' => false])['view'];
        self::assertSame($first, $view['board']['under']);
        self::assertNotSame($first, $view['board']['waste'][0]);
    }

    public function testInvalidMovesAreRejectedBeforeReachingTheGame(): void
    {
        [$user, $game] = $this->duel();
        $this->startNow($game);
        $this->client->loginUser($user);

        $this->api('POST', $this->url($game).'/deplacements', ['card' => 'coeur-1', 'to' => 'famille']);
        self::assertResponseStatusCodeSame(422);

        $this->api('POST', $this->url($game).'/deplacements', ['card' => 'coeur-as', 'to' => 'colonne-9']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUndoCostsAThousandPoints(): void
    {
        [$user, $game] = $this->duel();
        $this->startNow($game);
        $this->client->loginUser($user);

        // La pioche ne s'annule pas
        $view = $this->api('POST', $this->url($game).'/talon', ['auto' => false])['view'];
        self::assertFalse($view['board']['canUndo']);
        $this->api('POST', $this->url($game).'/retour');
        self::assertResponseStatusCodeSame(409);

        // Un déplacement de carte s'annule une fois, contre 1 000 points ; sans assez de points, c'est refusé
        // (un score n'est jamais négatif)
        $card = array_key_first($view['board']['targets']);
        $beforeMove = $view['board']['score'];
        $moved = $this->api('POST', $this->url($game).'/deplacements', ['card' => $card, 'to' => $view['board']['targets'][$card][0], 'auto' => false])['view'];
        self::assertSame($beforeMove + SolitaireScore::UNDO >= 0, $moved['board']['canUndo']);
        $view = $this->api('POST', $this->url($game).'/retour')['view'];

        if ($beforeMove + SolitaireScore::UNDO < 0) {
            self::assertResponseStatusCodeSame(409);
            self::assertGreaterThanOrEqual(0, $view['board']['score']);

            return;
        }

        self::assertResponseIsSuccessful();
        // Le score d'avant le déplacement (ses points sont annulés avec lui), moins 1 000
        self::assertSame($beforeMove + SolitaireScore::UNDO, $view['board']['score']);
        self::assertFalse($view['board']['canUndo']);

        $this->api('POST', $this->url($game).'/retour');
        self::assertResponseStatusCodeSame(409);
    }

    public function testStoppingShowsMyResultAndTheRoomWaitsForTheOthers(): void
    {
        [$user, $game] = $this->duel();
        $this->startNow($game);
        $this->client->loginUser($user);

        $view = $this->api('POST', $this->url($game).'/arret')['view'];

        self::assertResponseIsSuccessful();
        // Ma partie est finie, le duel attend l'autre joueur
        self::assertSame('attente', $view['phase']);
        self::assertTrue($view['board']['finished']);
        self::assertCount(4, $view['board']['details']);
        self::assertSame('Temps restant', $view['board']['details'][1]['label']);
        self::assertSame(RoomStatus::Waiting, $game->getRoom()->getStatus());

        // Le joueur est libre : il peut créer un autre salon. Le sien n'est plus dans sa liste (il ne peut pas jouer
        // contre lui-même : il le retrouve dans « Mes parties ») ; les autres joueurs le voient encore
        $state = $this->api('GET', '/api/v1/jeux/solitaire/salons');
        $id = $game->getRoom()->getId();
        self::assertNull($state['mine']);
        self::assertSame([], $state['rooms']);
        self::assertArrayNotHasKey('played', $state);

        $this->client->loginUser($this->createUser('Bob'));
        self::assertSame($id, $this->api('GET', '/api/v1/jeux/solitaire/salons')['rooms'][0]['id']);
        $this->client->loginUser($user);

        $this->api('POST', $this->url($game).'/talon', ['auto' => true]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testScoresStayHiddenUntilMyOwnGameIsOver(): void
    {
        [$fred, $game] = $this->duel();
        $bob = $this->createUser('Bob');
        static::getContainer()->get(GameRoomManager::class)->join($bob, $game->getRoom());
        $this->startNow($game);

        // Fred a fini ; Bob joue encore : il ne voit pas le score de Fred
        $this->client->loginUser($fred);
        $this->api('POST', $this->url($game).'/arret');

        $this->client->loginUser($bob);
        $view = $this->api('GET', $this->url($game))['view'];
        self::assertSame('jeu', $view['phase']);
        self::assertTrue($view['players'][1 - $view['me']]['done']);
        self::assertNull($view['players'][1 - $view['me']]['score']);

        // Bob finit : il voit le score de Fred, le duel est terminé (toutes les places jouées)
        $view = $this->api('POST', $this->url($game).'/arret')['view'];
        self::assertSame('terminee', $view['phase']);
        self::assertIsInt($view['players'][1 - $view['me']]['score']);
        self::assertTrue(null !== $view['winner'] || $view['draw']);

        $this->entityManager->clear();
        self::assertSame(RoomStatus::Closed, $this->entityManager->find(PatienceGame::class, $game->getId())?->getRoom()->getStatus());
    }

    public function testTimeIsUpOnlyAfterFiveMinutes(): void
    {
        [$user, $game] = $this->duel();
        $this->startNow($game);
        $this->client->loginUser($user);

        $body = $this->api('POST', $this->url($game).'/temps-ecoule');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Le temps n’est pas encore écoulé.', $body['detail']);

        $this->startNow($game, SolitaireScore::SECONDS + 5);
        $view = $this->api('POST', $this->url($game).'/temps-ecoule')['view'];

        self::assertResponseIsSuccessful();
        self::assertSame('attente', $view['phase']);
    }

    public function testLeavingEndsMyGameAtMyCurrentScore(): void
    {
        [$fred, $game] = $this->duel();
        $bob = $this->createUser('Bob');
        static::getContainer()->get(GameRoomManager::class)->join($bob, $game->getRoom());
        // Tout le temps est passé : plus de bonus de temps pour personne
        $this->startNow($game, SolitaireScore::SECONDS);
        $this->client->loginUser($fred);

        $this->client->request('POST', $this->url($game).'/abandon', [], [], ['HTTP_X_CSRF_TOKEN' => 'csrf-token']);
        self::assertResponseStatusCodeSame(204);

        // Bob arrête aussi : même donne, même score que Fred (qui garde le sien) : à égalité, celui qui a joué le premier gagne
        $this->client->loginUser($bob);
        $view = $this->api('POST', $this->url($game).'/arret')['view'];
        self::assertSame('terminee', $view['phase']);
        self::assertFalse($view['draw']);
        $fredPosition = array_values(array_filter($view['players'], static fn (array $player): bool => 'Fred' === $player['pseudo']))[0]['position'];
        self::assertSame($fredPosition, $view['winner']);
    }

    public function testJoiningAnOlderDuelAfterFinishingMyOwn(): void
    {
        // Le duel de Bob (créé avant), puis celui de Fred, que Fred a déjà joué (il reste ouvert pour les autres)
        $bob = $this->createUser('Bob');
        $manager = static::getContainer()->get(GameRoomManager::class);
        $older = $manager->create($bob, Game::Solitaire, 1, false, 2);
        $this->entityManager->getConnection()->executeStatement('UPDATE game_room SET created_at = :at WHERE id = :id', ['at' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), 'id' => $older->getId()]);
        [$fred, $mine] = $this->duel(3);
        $this->client->loginUser($fred);
        $this->client->request('POST', $this->url($mine).'/abandon', [], [], ['HTTP_X_CSRF_TOKEN' => 'csrf-token']);
        self::assertResponseStatusCodeSame(204);

        // Fred rejoint le duel de Bob : la table de ce duel s'ouvre (et pas « la partie n'a pas pu commencer »)
        $state = $this->api('POST', '/api/v1/jeux/solitaire/salons/'.$older->getId().'/places/2');
        self::assertResponseIsSuccessful();
        self::assertSame($older->getId(), $state['mine']['id']);
        self::assertSame('/jeux/solitaire/parties/'.$older->getId(), $state['mine']['playUrl']);
    }

    public function testAnExpiredGameNeverReopensInsteadOfANewOne(): void
    {
        // Fred a quitté la page de son duel sans finir ; son temps est écoulé depuis (personne n'a clos sa grille)
        [$fred, $old] = $this->duel(4);
        $this->startNow($old, SolitaireScore::SECONDS + 60);
        $this->client->loginUser($fred);

        // « Nouvelle partie » : un nouveau duel, pas l'ancien
        $state = $this->api('POST', '/api/v1/jeux/solitaire/salons', ['targetPoints' => 1, 'announcements' => false, 'players' => 2]);
        self::assertResponseStatusCodeSame(201);
        self::assertNotSame($old->getRoom()->getId(), $state['mine']['id']);
        self::assertSame('/jeux/solitaire/parties/'.$state['mine']['id'], $state['mine']['playUrl']);
    }

    public function testNoRobotsInSoloDuels(): void
    {
        [$user, $game] = $this->duel();
        $this->client->loginUser($user);

        $this->api('POST', '/api/v1/jeux/solitaire/salons/'.$game->getRoom()->getId().'/places/2/robot');
        self::assertContains($this->client->getResponse()->getStatusCode(), [404, 409]);
        self::assertFalse($this->api('GET', '/api/v1/jeux/solitaire/salons')['bots']);
    }

    public function testThePreparePagesConfirmBeforePlaying(): void
    {
        [, $game] = $this->duel(3);
        $this->client->loginUser($this->createUser('Bob'));

        $this->client->request('GET', '/jeux/solitaire/participer?joueurs=4');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main#preparation.prepare');
        self::assertSelectorTextContains('h1', 'Préparez-vous');
        self::assertSelectorTextContains('.prepare__details', '3 cartes à la fois');
        self::assertSelectorExists('.prepare__play');

        $this->client->request('GET', '/jeux/solitaire/salons/'.$game->getRoom()->getId().'/participer');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.prepare__details', 'Fred');
        self::assertSelectorExists('main#preparation[data-position="2"]');

        // Pas de page d'entre-deux pour les jeux en direct, ni de nombre de joueurs impossible
        $this->client->request('GET', '/jeux/belote/participer');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/jeux/solitaire/participer?joueurs=9');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyThePlayersSeeTheDuel(): void
    {
        [, $game] = $this->duel();

        $this->client->request('GET', $this->url($game));
        self::assertResponseStatusCodeSame(401);

        $this->client->loginUser($this->createUser('Curieux'));
        $this->api('GET', $this->url($game));
        self::assertResponseStatusCodeSame(403);
    }

    public function testActionsNeedTheFormToken(): void
    {
        [$user, $game] = $this->duel();
        $this->startNow($game);
        $this->client->loginUser($user);

        $this->client->request('POST', $this->url($game).'/talon', [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Fred crée un duel : sa partie commence aussitôt.
     *
     * @return array{User, PatienceGame}
     */
    private function duel(int $players = 2, Game $game = Game::Solitaire): array
    {
        $user = $this->createUser('Fred');
        $room = static::getContainer()->get(GameRoomManager::class)->create($user, $game, 1, false, $players);
        $patience = $this->entityManager->getRepository(PatienceGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');

        return [$user, $patience];
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
        return '/api/v1/jeux/solitaire/parties/'.$game->getId();
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
