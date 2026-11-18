<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

/**
 * Accès à l'administration (rôle ROLE_ADMIN) et tableau de bord.
 */
final class DashboardAdminControllerTest extends AdminTestCase
{
    public function testVisitorIsSentToTheLoginPage(): void
    {
        $this->client->request('GET', '/admin/tableau-de-bord');

        self::assertResponseRedirects('/connexion');
    }

    public function testPlayerWithoutTheAdminRoleIsRefused(): void
    {
        $this->client->loginUser($this->createUser('Fred'));

        foreach (['/admin/tableau-de-bord', '/admin/jeux/belote/reglages', '/admin/jeux/belote/statistiques'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testAdminSeesTheDashboard(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/tableau-de-bord');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('h1', 'Tableau de bord');
        self::assertSelectorExists('main#tableau-de-bord');
        self::assertSelectorTextContains('.admin__link--active[aria-current="page"]', 'Tableau de bord');
    }

    public function testAccountMenuShowsTheAdminLinkToAdminsOnly(): void
    {
        $this->client->loginUser($this->createUser('Fred'));
        $this->client->request('GET', '/mon-profil');
        self::assertSelectorNotExists('.account-menu a[href="/admin/tableau-de-bord"]');

        $this->client->loginUser($this->createUser('Admin', admin: true));
        $this->client->request('GET', '/mon-profil');
        self::assertSelectorTextContains('.account-menu a[href="/admin/tableau-de-bord"]', 'Administration');
    }

    public function testUnknownGameIsNotFound(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $this->client->request('GET', '/admin/jeux/inconnu/reglages');

        self::assertResponseStatusCodeSame(404);
    }
}
