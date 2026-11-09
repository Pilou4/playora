<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Profile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SecurityControllerTest extends WebTestCase
{
    private const string PASSWORD = 'Belote-Atout-Coeur-2026!';
    private const string BAD_CREDENTIALS = 'Adresse e-mail ou mot de passe incorrect.';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');

        $container = static::getContainer();
        $container->get('cache.rate_limiter')->clear();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.Profile::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testPageIsDisplayed(): void
    {
        $crawler = $this->client->request('GET', '/connexion');

        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Se connecter');
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorExists('main#connexion');
        self::assertSame('noindex', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('page', $crawler->filter('.site-header__account a[href="/connexion"]')->attr('aria-current'));
    }

    public function testEveryFieldHasALabelAndAnAutocomplete(): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $inputs = $crawler->filter('form.form input:not([type="hidden"])');

        self::assertCount(2, $inputs);
        $inputs->each(static function (Crawler $input) use ($crawler): void {
            self::assertCount(1, $crawler->filter('label[for="'.$input->attr('id').'"]'));
            self::assertNotEmpty($input->attr('autocomplete'));
        });
    }

    public function testErrorPlacesAreReserved(): void
    {
        $this->client->request('GET', '/connexion');

        self::assertSelectorCount(2, '.field__error');
        self::assertSelectorExists('.form__message');
        self::assertSelectorNotExists('.form__message--visible');
    }

    public function testVerifiedUserCanLogInAndOut(): void
    {
        $this->createUser('fred@exemple.fr', 'Fred', verified: true);

        $this->login('fred@exemple.fr', self::PASSWORD);

        self::assertResponseRedirects('/');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.site-header__pseudo', 'Fred');

        $this->client->submit($crawler->selectButton('Se déconnecter')->form());
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorNotExists('.site-header__pseudo');
    }

    public function testEmailIsCaseInsensitive(): void
    {
        $this->createUser('fred@exemple.fr', 'Fred', verified: true);

        $this->login('Fred@Exemple.fr', self::PASSWORD);

        self::assertResponseRedirects('/');
    }

    public function testWrongPasswordAndUnknownEmailGiveTheSameMessage(): void
    {
        $this->createUser('fred@exemple.fr', 'Fred', verified: true);

        $this->login('fred@exemple.fr', 'Mauvais-mot-de-passe-1');
        $this->client->followRedirect();
        self::assertSelectorTextSame('.form__message--visible', self::BAD_CREDENTIALS);

        $this->login('inconnu@exemple.fr', self::PASSWORD);
        $this->client->followRedirect();
        self::assertSelectorTextSame('.form__message--visible', self::BAD_CREDENTIALS);
    }

    public function testUnverifiedUserMustConfirmFirst(): void
    {
        $this->createUser('fred@exemple.fr', 'Fred');

        $this->login('fred@exemple.fr', self::PASSWORD);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.form__message--visible', 'Adresse pas encore confirmée');
        self::assertSelectorNotExists('.site-header__pseudo');
    }

    public function testUnverifiedMessageIsOnlyShownWithTheRightPassword(): void
    {
        $this->createUser('fred@exemple.fr', 'Fred');

        $this->login('fred@exemple.fr', 'Mauvais-mot-de-passe-1');
        $this->client->followRedirect();

        self::assertSelectorTextSame('.form__message--visible', self::BAD_CREDENTIALS);
    }

    public function testDeletedAccountCannotLogIn(): void
    {
        $user = $this->createUser('fred@exemple.fr', 'Fred', verified: true);
        $user->markAsDeleted();
        $this->entityManager->flush();

        $this->login('fred@exemple.fr', self::PASSWORD);
        $this->client->followRedirect();

        self::assertSelectorTextSame('.form__message--visible', self::BAD_CREDENTIALS);
    }

    public function testLoginFromAnotherSiteIsRefused(): void
    {
        $this->createUser('fred@exemple.fr', 'Fred', verified: true);
        // Ce que le navigateur envoie quand un autre site soumet le formulaire
        $this->client->setServerParameter('HTTP_SEC_FETCH_SITE', 'cross-site');
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://site-pirate.example');

        $this->login('fred@exemple.fr', self::PASSWORD);
        $this->client->followRedirect();

        self::assertSelectorNotExists('.site-header__pseudo');
    }

    public function testAttemptsAreLimited(): void
    {
        $this->createUser('fred@exemple.fr', 'Fred', verified: true);

        for ($i = 0; $i < 5; ++$i) {
            $this->login('fred@exemple.fr', 'Mauvais-mot-de-passe-1');
        }

        // Même le bon mot de passe est refusé pendant le blocage
        $this->login('fred@exemple.fr', self::PASSWORD);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.form__message--visible', 'Trop de tentatives');
    }

    private function login(string $email, string $password): void
    {
        $crawler = $this->client->request('GET', '/connexion');

        $this->client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => $email,
            '_password' => $password,
        ]));
    }

    private function createUser(string $email, string $pseudo, bool $verified = false): User
    {
        $user = new User($email, $pseudo);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));

        if ($verified) {
            $user->markEmailAsVerified();
        }

        // Avec un avatar : sinon chaque page renvoie vers « Choisissez votre avatar »
        $profile = new Profile($user);
        $profile->setAvatar('portrait.0.0.1.0.2.2.0.0.0.0.0.0.0.1.0.0.0.0.1');
        $this->entityManager->persist($user);
        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $user;
    }
}
