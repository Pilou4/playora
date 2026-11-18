<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\PresidentGame;
use App\Entity\User;
use App\Enum\Game;
use App\Service\GameRoomManager;

/**
 * Statistiques du Président : chiffres, liste triable des parties, détail d'une partie manche par manche.
 */
final class PresidentStatisticsAdminControllerTest extends AdminTestCase
{
    public function testStatisticsWithoutGames(): void
    {
        $this->client->loginUser($this->createUser('Admin', admin: true));
        $this->client->request('GET', '/admin/jeux/president/statistiques');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('h1', 'Président');
        self::assertSelectorExists('main#statistiques');
        self::assertSelectorTextContains('.admin-games__empty', 'Aucune partie');
        self::assertSelectorTextContains('.admin__link--active[aria-current="page"]', 'Statistiques');
    }

    public function testPlayerWithoutTheAdminRoleIsRefused(): void
    {
        $this->client->loginUser($this->createUser('Fred'));
        $this->client->request('GET', '/admin/jeux/president/statistiques');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGameIsListedAndShowsItsDetail(): void
    {
        $admin = $this->createUser('Admin', admin: true);
        $this->presidentAgainstBots($this->createUser('Fred'));

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/jeux/president/statistiques');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.admin-games__table tbody tr');
        self::assertSelectorTextContains('.admin-games__table tbody', 'Fred');
        self::assertSelectorTextContains('.admin-badge--playing', 'En cours');

        $this->client->click($crawler->filter('.admin-games__link')->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main#partie');
        self::assertSelectorCount(3, '#joueurs-titre + .admin-games__scroll tbody tr');
        self::assertSelectorTextContains('.admin-stats__grid', '9 points (3 par joueur)');
    }

    public function testGamesCanBeSortedByEveryColumn(): void
    {
        $admin = $this->createUser('Admin', admin: true);
        $this->presidentAgainstBots($this->createUser('Fred'));
        $this->client->loginUser($admin);

        foreach (['numero', 'debut', 'manches', 'etat'] as $column) {
            foreach (['asc', 'desc'] as $direction) {
                $this->client->request('GET', '/admin/jeux/president/statistiques?tri='.$column.'&ordre='.$direction);
                self::assertResponseIsSuccessful($column.' '.$direction);
                self::assertSelectorCount(1, '.admin-games__table tbody tr');
            }
        }

        $this->client->request('GET', '/admin/jeux/president/statistiques?tri=inconnu');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOutOfRangePageAndUnknownGameAreNotFound(): void
    {
        $this->client->loginUser($this->createUser('Admin', admin: true));

        $this->client->request('GET', '/admin/jeux/president/statistiques?page=2');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/jeux/president/parties/999999');
        self::assertResponseStatusCodeSame(404);
    }

    private function presidentAgainstBots(User $user): PresidentGame
    {
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($user, Game::President, 3, false, 3);
        $manager->addBot($user, $room, 2);
        $manager->addBot($user, $room, 3);

        return $this->entityManager->getRepository(PresidentGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');
    }
}
