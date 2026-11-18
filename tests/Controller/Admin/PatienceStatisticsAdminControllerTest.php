<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\PatienceGame;
use App\Entity\User;
use App\Service\Admin\PatienceStatistics;
use App\Service\PatienceTable;

/**
 * Statistiques du solitaire : chiffres, liste triable des parties, détail d'une partie manche par manche.
 */
final class PatienceStatisticsAdminControllerTest extends AdminTestCase
{
    public function testStatisticsWithoutDuels(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $this->client->request('GET', '/admin/jeux/solitaire/statistiques');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('h1', 'solitaire');
        self::assertSelectorExists('main#statistiques');
        self::assertSelectorTextContains('.admin-games__empty', 'Aucune partie');
        self::assertSelectorTextContains('.admin__link--active[aria-current="page"]', 'Statistiques');
    }

    public function testPlayerWithoutTheAdminRoleIsRefused(): void
    {
        $this->client->loginUser($this->createUser('Fred'));
        $this->client->request('GET', '/admin/jeux/solitaire/statistiques');

        self::assertResponseStatusCodeSame(403);
    }

    public function testFinishedDuelIsListedWithItsRounds(): void
    {
        $admin = $this->createUser('Admin', admin: true);
        $fred = $this->createUser('Fred');
        $bob = $this->createUser('Bob');
        $game = $this->soloDuel($fred, $bob);

        // Les deux parties commencent tout de suite, puis Fred et Bob les arrêtent : le duel est terminé
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE solitaire_board SET started_at = :start WHERE game_id = :game',
            ['start' => (new \DateTimeImmutable('-10 seconds'))->format('Y-m-d H:i:s'), 'game' => $game->getId()],
        );
        // Grilles et joueur relus en base (l'heure de début vient d'y être changée)
        $this->entityManager->clear();
        $game = $this->entityManager->find(PatienceGame::class, $game->getId()) ?? self::fail('Partie introuvable.');
        $fred = $this->entityManager->find(User::class, $fred->getId()) ?? self::fail('Joueur introuvable.');
        $bob = $this->entityManager->find(User::class, $bob->getId()) ?? self::fail('Joueur introuvable.');
        static::getContainer()->get(PatienceTable::class)->stop($fred, $game);
        static::getContainer()->get(PatienceTable::class)->stop($bob, $game);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/jeux/solitaire/statistiques');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.admin-games__table tbody tr');
        self::assertSelectorTextContains('.admin-games__table tbody', 'Fred');
        self::assertSelectorTextContains('.admin-games__table tbody', 'Bob');
        self::assertSelectorNotExists('.admin-badge--playing');

        $this->client->click($crawler->filter('.admin-games__link')->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorExists('main#partie');
        self::assertSelectorCount(2, '#joueurs-titre + .admin-games__scroll tbody tr');
        // Une ligne par joueur et par manche
        self::assertSelectorCount(2, '#manches-titre + .admin-games__scroll tbody tr');
        self::assertSelectorTextContains('#manches-titre + .admin-games__scroll tbody', '/ 52');
    }

    public function testDuelsCanBeSortedByEveryColumn(): void
    {
        $admin = $this->createUser('Admin', admin: true);
        $this->soloDuel($this->createUser('Fred'), $this->createUser('Bob'));
        $this->client->loginUser($admin);

        foreach (['numero', 'debut', 'manches', 'etat'] as $column) {
            foreach (['asc', 'desc'] as $direction) {
                $this->client->request('GET', '/admin/jeux/solitaire/statistiques?tri='.$column.'&ordre='.$direction);
                self::assertResponseIsSuccessful($column.' '.$direction);
                self::assertSelectorCount(1, '.admin-games__table tbody tr');
            }
        }

        $this->client->request('GET', '/admin/jeux/solitaire/statistiques?tri=inconnu');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/jeux/solitaire/statistiques?page=2');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDuelsAreSplitIntoPages(): void
    {
        $admin = $this->createUser('Admin', admin: true);

        foreach (range(1, PatienceStatistics::PER_PAGE + 1) as $number) {
            $this->soloDuel($this->createUser('Joueur '.$number), $this->createUser('Rival '.$number));
        }

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/jeux/solitaire/statistiques');

        // Le menu de l'admin ne doit pas écraser le nombre de pages de la liste
        self::assertSelectorCount(PatienceStatistics::PER_PAGE, '.admin-games__table tbody tr');
        self::assertSelectorTextContains('.admin-pager__current', 'Page 1 sur 2');

        $this->client->request('GET', '/admin/jeux/solitaire/statistiques?page=2');
        self::assertSelectorCount(1, '.admin-games__table tbody tr');
        self::assertSelectorTextContains('.admin-pager__current', 'Page 2 sur 2');
    }

    public function testEachSpiderHasItsStatistics(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));

        foreach (['spider-debutant' => 'Spider débutant', 'spider' => 'Spider', 'spider-pro' => 'Spider Pro'] as $game => $label) {
            $this->client->request('GET', '/admin/jeux/'.$game.'/statistiques');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', $label);
            self::assertSelectorTextContains('.admin-games__empty', 'Aucune partie');
        }
    }

    public function testUnknownDuelIsNotFound(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $this->client->request('GET', '/admin/jeux/solitaire/parties/999999');

        self::assertResponseStatusCodeSame(404);
    }
}
