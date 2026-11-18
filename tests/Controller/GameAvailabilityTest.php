<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\GameSetting;
use App\Enum\Game;
use App\Service\GameAvailability;
use App\Tests\Controller\Admin\AdminTestCase;

/**
 * Jeu inactif (réglé dans l'admin) : caché de la liste des jeux et fermé aux joueurs ; les admins le voient et y jouent.
 */
final class GameAvailabilityTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testEveryGameIsListedWhileActive(): void
    {
        $this->client->request('GET', '/jeux');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(\count(Game::cases()), '.game');
        self::assertSelectorTextNotContains('main', 'hors ligne');
    }

    public function testAnInactiveGameIsHiddenFromPlayers(): void
    {
        $this->deactivate(Game::Golf);

        foreach (['/jeux', '/'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(\count(Game::cases()) - 1, '.game');
            self::assertCount(0, $crawler->filter('a.game__play[href="/jeux/golf"]'), $url);
        }

        $this->client->request('GET', '/jeux/golf');
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->createUser('Lea'));
        $this->client->request('GET', '/jeux/golf');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/jeux/golf/participer?joueurs=2');
        self::assertResponseStatusCodeSame(404);

        // L'API refuse aussi (même format d'erreur que partout)
        $this->api('GET', '/api/v1/jeux/golf/salons');
        self::assertResponseStatusCodeSame(404);
        $error = $this->api('POST', '/api/v1/jeux/golf/salons', ['targetPoints' => 9, 'players' => 2]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $error['code'] ?? null);

        // Les autres jeux restent ouverts
        $this->client->request('GET', '/jeux/belote');
        self::assertResponseIsSuccessful();
    }

    public function testAdminsStillSeeAndPlayAnInactiveGame(): void
    {
        $this->deactivate(Game::Golf);
        $this->client->loginUser($this->createUser('Fred', admin: true));

        $crawler = $this->client->request('GET', '/jeux');
        self::assertSelectorCount(\count(Game::cases()), '.game');
        self::assertStringContainsString('hors ligne', $crawler->filter('.game__info')->eq(6)->text());

        $this->client->request('GET', '/jeux/golf');
        self::assertResponseIsSuccessful();

        $this->api('POST', '/api/v1/jeux/golf/salons', ['targetPoints' => 9, 'players' => 2]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testTheListIsReadOncePerRequest(): void
    {
        $this->deactivate(Game::Rami);
        $availability = static::getContainer()->get(GameAvailability::class);

        self::assertFalse($availability->isActive(Game::Rami));
        self::assertTrue($availability->isActive(Game::Belote));

        // Réactivé entre deux requêtes : la liste est relue après reset()
        $this->entityManager->getRepository(GameSetting::class)->findOneBy(['game' => Game::Rami])?->setActive(true);
        $this->entityManager->flush();
        self::assertFalse($availability->isActive(Game::Rami));
        $availability->reset();
        self::assertTrue($availability->isActive(Game::Rami));
    }

    private function deactivate(Game $game): void
    {
        $setting = new GameSetting($game);
        $setting->setActive(false);
        $this->entityManager->persist($setting);
        $this->entityManager->flush();
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
}
