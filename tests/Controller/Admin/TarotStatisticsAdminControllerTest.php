<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\TarotDeal;
use App\Game\Tarot\TarotContract;
use App\Game\Tarot\TarotDealResult;

/**
 * Statistiques du tarot : chiffres, liste triable des parties, détail d'une partie.
 */
final class TarotStatisticsAdminControllerTest extends AdminTestCase
{
    public function testStatisticsWithoutGames(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $this->client->request('GET', '/admin/jeux/tarot/statistiques');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('h1', 'tarot');
        self::assertSelectorExists('main#statistiques');
        self::assertSelectorTextContains('.admin-games__empty', 'Aucune partie');
        self::assertSelectorTextContains('.admin__link--active[aria-current="page"]', 'Statistiques');
    }

    public function testPlayerWithoutTheAdminRoleIsRefused(): void
    {
        $this->client->loginUser($this->createUser('Fred'));
        $this->client->request('GET', '/admin/jeux/tarot/statistiques');

        self::assertResponseStatusCodeSame(403);
    }

    public function testStatisticsListTheGamesAndShowTheirDetail(): void
    {
        $admin = $this->createUser('Admin', admin: true);
        $game = $this->tarotAgainstBots($this->createUser('Fred'), 5);
        $this->entityManager->persist(new TarotDeal($game, new TarotDealResult(
            1, 0, 1, TarotContract::Garde, false, 70, 1, 51, 70, [-70, -140, 70, 70, 70], 1, [], false, null, 2,
        )));
        $this->entityManager->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/jeux/tarot/statistiques');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.admin-games__table tbody tr');
        self::assertSelectorTextContains('.admin-games__table tbody', 'Fred');
        self::assertSelectorTextContains('.admin-games__table tbody', 'Robot');
        self::assertSelectorTextContains('.admin-badge--playing', 'En cours');
        self::assertSelectorTextContains('#contrats-titre + .admin-stats__grid', 'Garde');

        $this->client->click($crawler->filter('.admin-games__link')->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorExists('main#partie');
        self::assertSelectorCount(5, '#joueurs-titre + .admin-games__scroll tbody tr');
        self::assertSelectorTextContains('.admin-badge--chute', 'Chuté');
        self::assertSelectorTextContains('#donnes-titre + .admin-games__scroll tbody', 'Garde');
        self::assertSelectorTextContains('#donnes-titre + .admin-games__scroll tbody', 'Défense');
        // Une colonne de points par joueur
        self::assertSelectorCount(15, '#donnes-titre + .admin-games__scroll thead th');
    }

    public function testGamesCanBeSortedByEveryColumn(): void
    {
        $admin = $this->createUser('Admin', admin: true);
        $this->tarotAgainstBots($this->createUser('Fred'));
        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin/jeux/tarot/statistiques');
        self::assertSelectorTextContains('th[aria-sort="descending"]', 'N°');

        foreach (['numero', 'debut', 'joueurs', 'donnes', 'etat'] as $column) {
            foreach (['asc', 'desc'] as $direction) {
                $this->client->request('GET', '/admin/jeux/tarot/statistiques?tri='.$column.'&ordre='.$direction);
                self::assertResponseIsSuccessful($column.' '.$direction);
                self::assertSelectorCount(1, '.admin-games__table tbody tr');
            }
        }

        $this->client->request('GET', '/admin/jeux/tarot/statistiques?tri=inconnu');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOutOfRangePageIsNotFound(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $this->client->request('GET', '/admin/jeux/tarot/statistiques?page=2');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownGameDetailIsNotFound(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $this->client->request('GET', '/admin/jeux/tarot/parties/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testBeloteGameIsNotShownAsATarotGame(): void
    {
        $admin = $this->createUser('Admin', admin: true);
        $belote = $this->gameAgainstBots($this->createUser('Fred'));
        $this->client->loginUser($admin);

        // Les numéros de partie de belote et de tarot sont indépendants : l'adresse belote reste à la belote
        $this->client->request('GET', '/admin/jeux/belote/parties/'.$belote->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#joueurs-titre + .admin-games__scroll thead', 'Équipe');
    }
}
