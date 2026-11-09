<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Profile;
use App\Entity\User;
use App\Repository\ProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProfileControllerTest extends WebTestCase
{
    private const string AVATAR = 'portrait.0.0.1.0.2.2.0.0.0.0.0.0.0.1.0.0.0.0.1';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Jeton CSRF « sans session » : Symfony vérifie que la requête vient bien du site
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    // ---------- Avatar obligatoire ----------

    public function testPlayerWithoutAvatarIsSentToChooseOne(): void
    {
        $this->client->loginUser($this->createUser('Fred'));

        foreach (['/', '/jeux/belote', '/mon-profil'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/bienvenue', message: $url);
        }
    }

    public function testApiAndAvatarPagesStayReachableWithoutAvatar(): void
    {
        $this->client->loginUser($this->createUser('Fred'));

        $this->client->request('GET', '/api/v1/jeux/belote/salons');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/mon-profil/avatar');
        self::assertResponseIsSuccessful();
    }

    public function testWelcomePageShowsTheGallery(): void
    {
        $this->client->loginUser($this->createUser('Fred'));

        $this->client->request('GET', '/bienvenue');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('h1', 'Bienvenue Fred');
        self::assertSelectorExists('main#bienvenue');
        self::assertSelectorCount(24, 'input.welcome__radio');
        // Sans avatar, le menu du compte affiche l'initiale
        self::assertSelectorTextContains('.account-menu__avatar--initial', 'F');
    }

    public function testChoosingAnAvatarSavesItAndOpensTheSite(): void
    {
        $user = $this->createUser('Fred');
        $this->client->loginUser($user);

        $this->chooseAvatar(self::AVATAR);

        self::assertResponseRedirects('/');
        self::assertSame(self::AVATAR, $this->avatarOf($user));

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('/avatars/'.self::AVATAR.'-visage.svg?v=5', $this->client->getCrawler()->filter('.account-menu__toggle .account-menu__avatar')->attr('src'));
    }

    public function testContinuingWithoutChoosingIsRefused(): void
    {
        $user = $this->createUser('Fred');
        $this->client->loginUser($user);

        $this->chooseAvatar(null);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.welcome__error', 'Choisissez un avatar');
        self::assertNull($this->avatarOf($user));
    }

    public function testOnlyGalleryAvatarsAreAccepted(): void
    {
        $user = $this->createUser('Fred');
        $this->client->loginUser($user);

        $this->client->request('POST', '/bienvenue', ['avatar' => 'portrait.99.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0', '_token' => 'csrf-token']);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->avatarOf($user));
    }

    public function testWelcomeFormFromAnotherSiteIsRefused(): void
    {
        $user = $this->createUser('Fred');
        $this->client->loginUser($user);

        $this->client->request('POST', '/bienvenue', ['avatar' => self::AVATAR, '_token' => 'csrf-token'], [], [
            'HTTP_ORIGIN' => 'https://pirate.example',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->avatarOf($user));
    }

    public function testPlayerWithAnAvatarSkipsTheWelcomePage(): void
    {
        $this->client->loginUser($this->createUser('Fred', self::AVATAR));

        $this->client->request('GET', '/bienvenue');

        self::assertResponseRedirects('/');
    }

    // ---------- Menu du compte ----------

    public function testAccountMenuShowsTheAvatarAndTheLinks(): void
    {
        $this->client->loginUser($this->createUser('Fred', self::AVATAR));

        $crawler = $this->client->request('GET', '/');

        self::assertSame('false', $crawler->filter('.account-menu__toggle')->attr('aria-expanded'));
        self::assertSame('menu-compte', $crawler->filter('.account-menu__toggle')->attr('aria-controls'));
        self::assertSelectorTextContains('.account-menu__toggle', 'Fred');
        self::assertCount(1, $crawler->filter('.account-menu a[href="/mon-profil"]'));
        self::assertCount(1, $crawler->filter('.account-menu a[href="/mon-profil/avatar"]'));
        self::assertCount(1, $crawler->selectButton('Se déconnecter'));
    }

    // ---------- Pseudo ----------

    public function testProfilePageNeedsAnAccount(): void
    {
        $this->client->request('GET', '/mon-profil');

        self::assertResponseRedirects();
        self::assertStringContainsString('/connexion', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testProfilePageShowsTheAvatarAndThePseudo(): void
    {
        $this->client->loginUser($this->createUser('Fred', self::AVATAR));

        $crawler = $this->client->request('GET', '/mon-profil');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorExists('main#mon-profil');
        self::assertSame('Fred', $crawler->filter('#profil_pseudo')->attr('value'));
        self::assertSame('/avatars/'.self::AVATAR.'.svg?v=5', $crawler->filter('.profile__image')->attr('src'));
    }

    public function testPlayerChangesHisPseudo(): void
    {
        $user = $this->createUser('Fred', self::AVATAR);
        $this->client->loginUser($user);

        $this->changePseudo('Frédo');

        self::assertResponseRedirects('/mon-profil');
        self::assertSame('Frédo', $this->reload($user)->getPseudo());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.profile__notice', 'pseudo est enregistré');
        self::assertSelectorTextContains('.site-header__pseudo', 'Frédo');
    }

    public function testPseudoOfAnotherPlayerIsRefused(): void
    {
        $this->createUser('Léo', self::AVATAR);
        $user = $this->createUser('Fred', self::AVATAR);
        $this->client->loginUser($user);

        // Comparaison sans majuscules ni accents, comme à l'inscription
        $this->changePseudo('leo');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#profil_pseudo_error', 'déjà pris');
        self::assertSame('Fred', $this->reload($user)->getPseudo());
    }

    public function testInvalidPseudoIsRefused(): void
    {
        $user = $this->createUser('Fred', self::AVATAR);
        $this->client->loginUser($user);

        $this->changePseudo('<b>');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Fred', $this->reload($user)->getPseudo());
    }

    private function chooseAvatar(?string $code): void
    {
        $crawler = $this->client->request('GET', '/bienvenue');
        $form = $crawler->selectButton('C’est parti')->form();

        if (null !== $code) {
            $form['avatar']->select($code);
        }

        $this->client->submit($form);
    }

    private function changePseudo(string $pseudo): void
    {
        $crawler = $this->client->request('GET', '/mon-profil');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['profil[pseudo]' => $pseudo]));
    }

    private function createUser(string $pseudo, ?string $avatar = null): User
    {
        $user = new User(bin2hex(random_bytes(6)).'@exemple.fr', $pseudo);
        $user->setPassword('pas-utilise-dans-ces-tests');
        $user->markEmailAsVerified();
        $profile = new Profile($user);
        $profile->setAvatar($avatar);
        $this->entityManager->persist($user);
        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $user;
    }

    private function reload(User $user): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(User::class, $user->getId()) ?? self::fail('Compte introuvable.');
    }

    private function avatarOf(User $user): ?string
    {
        $fresh = $this->reload($user);

        return static::getContainer()->get(ProfileRepository::class)->findOneByUser($fresh)?->getAvatar();
    }

    private function purge(): void
    {
        $connection = $this->entityManager->getConnection();

        foreach (['reset_password_request', 'game_room_seat', 'game_room', 'profile', '`user`'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
    }
}
