<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\BeloteDeal;
use App\Game\Belote\DealResult;
use App\Game\Belote\Outcome;
use App\Game\Belote\Suit;

/**
 * Statistiques d'un jeu : chiffres, liste triable des parties, détail d'une partie.
 */
final class GameStatisticsAdminControllerTest extends AdminTestCase
{
    public function testStatisticsWithoutGames(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $this->client->request('GET', '/admin/jeux/belote/statistiques');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorExists('main#statistiques');
        self::assertSelectorTextContains('.admin-games__empty', 'Aucune partie');
    }

    public function testStatisticsListTheGamesAndShowTheirDetail(): void
    {
        $admin = $this->createUser('Admin', admin: true);
        $game = $this->gameAgainstBots($this->createUser('Fred'));
        $this->entityManager->persist(new BeloteDeal($game, new DealResult(1, 0, 1, Suit::Hearts, Outcome::Failed, [40, 122], [0, 162], null, null, 0)));
        $this->entityManager->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/jeux/belote/statistiques');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.admin-games__table tbody tr');
        self::assertSelectorTextContains('.admin-games__table tbody', 'Fred');
        self::assertSelectorTextContains('.admin-games__table tbody', 'Robot');
        self::assertSelectorTextContains('.admin-badge--playing', 'En cours');

        $this->client->click($crawler->filter('.admin-games__link')->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorExists('main#partie');
        self::assertSelectorCount(4, '#joueurs-titre + .admin-games__scroll tbody tr');
        self::assertSelectorTextContains('.admin-badge--chute', 'Chuté');
        self::assertSelectorTextContains('#donnes-titre + .admin-games__scroll tbody', 'Cœur');
    }

    public function testGamesCanBeSortedByEveryColumn(): void
    {
        $admin = $this->createUser('Admin', admin: true);
        $this->gameAgainstBots($this->createUser('Fred'));
        $this->client->loginUser($admin);

        // Par défaut : les plus récentes d'abord
        $this->client->request('GET', '/admin/jeux/belote/statistiques');
        self::assertSelectorTextContains('th[aria-sort="descending"]', 'N°');

        foreach (['numero', 'debut', 'equipe-1', 'equipe-2', 'score', 'donnes', 'etat'] as $column) {
            foreach (['asc', 'desc'] as $direction) {
                $this->client->request('GET', '/admin/jeux/belote/statistiques?tri='.$column.'&ordre='.$direction);
                self::assertResponseIsSuccessful($column.' '.$direction);
                self::assertSelectorCount(1, '.admin-games__table tbody tr');
                self::assertSelectorExists('th[aria-sort="'.('asc' === $direction ? 'ascending' : 'descending').'"]');
            }
        }

        $this->client->request('GET', '/admin/jeux/belote/statistiques?tri=inconnu');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDetailTablesCanBeSortedInThePage(): void
    {
        $admin = $this->createUser('Admin', admin: true);
        $game = $this->gameAgainstBots($this->createUser('Fred'));
        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/jeux/belote/parties/'.$game->getId());

        self::assertSelectorExists('#joueurs-titre + .admin-games__scroll table.admin-sortable th button.admin-sort');
    }

    public function testOutOfRangePageIsNotFound(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $this->client->request('GET', '/admin/jeux/belote/statistiques?page=2');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownGameDetailIsNotFound(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $this->client->request('GET', '/admin/jeux/belote/parties/999999');

        self::assertResponseStatusCodeSame(404);
    }
}
