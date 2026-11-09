<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Profile;
use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ResetPasswordControllerTest extends WebTestCase
{
    private const string OLD_PASSWORD = 'Belote-Atout-Coeur-2026!';
    private const string NEW_PASSWORD = 'Tarot-Excuse-Petit-2027?';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Jeton CSRF « sans session » : Symfony vérifie que la requête vient bien du site
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');

        $container = static::getContainer();
        $container->get('cache.rate_limiter')->clear();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testLoginPageLinksToTheForgottenPasswordPage(): void
    {
        $crawler = $this->client->request('GET', '/connexion');

        self::assertSame('/mot-de-passe-oublie', $crawler->filter('.form__forgot a')->attr('href'));
    }

    public function testPageIsDisplayed(): void
    {
        $crawler = $this->client->request('GET', '/mot-de-passe-oublie');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorExists('main#mot-de-passe-oublie');
        self::assertSame('noindex', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('email', $crawler->filter('#mot_de_passe_oublie_email')->attr('autocomplete'));
    }

    public function testKnownAddressReceivesALink(): void
    {
        $this->createUser('fred@exemple.fr');

        $this->requestLink('fred@exemple.fr');

        self::assertResponseRedirects('/mot-de-passe-oublie/verifiez-vos-emails');
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'To', 'fred@exemple.fr');
        self::assertEmailSubjectContains($email, 'nouveau mot de passe');
        self::assertEmailHtmlBodyContains($email, 'http://localhost/mot-de-passe-oublie/nouveau/');
        self::assertCount(1, $this->entityManager->getRepository(ResetPasswordRequest::class)->findAll());
    }

    public function testUnknownAddressGetsExactlyTheSameAnswer(): void
    {
        $this->requestLink('personne@exemple.fr');

        self::assertResponseRedirects('/mot-de-passe-oublie/verifiez-vos-emails');
        self::assertQueuedEmailCount(0);

        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Si un compte existe pour personne@exemple.fr');
    }

    public function testOnlyOneLinkPerHour(): void
    {
        $this->createUser('fred@exemple.fr');

        $this->requestLink('fred@exemple.fr');
        $this->requestLink('fred@exemple.fr');

        // Même réponse, mais aucun second e-mail
        self::assertResponseRedirects('/mot-de-passe-oublie/verifiez-vos-emails');
        self::assertQueuedEmailCount(0);
        self::assertCount(1, $this->entityManager->getRepository(ResetPasswordRequest::class)->findAll());
    }

    public function testRequestsAreLimitedPerAddress(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->requestLink("joueur{$i}@exemple.fr");
        }

        $this->requestLink('encore@exemple.fr');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form__message', 'Trop de demandes');
    }

    public function testFormFromAnotherSiteIsRefused(): void
    {
        $this->createUser('fred@exemple.fr');

        $this->client->request('POST', '/mot-de-passe-oublie', ['mot_de_passe_oublie' => ['email' => 'fred@exemple.fr', '_token' => 'csrf-token']], [], [
            'HTTP_ORIGIN' => 'https://pirate.example',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertQueuedEmailCount(0);
    }

    public function testLinkLeavesTheAddressBarAndThePageIsNeverCached(): void
    {
        $this->createUser('fred@exemple.fr');
        $link = $this->link('fred@exemple.fr');

        $this->client->request('GET', $link);
        self::assertResponseRedirects('/mot-de-passe-oublie/nouveau');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorExists('main#nouveau-mot-de-passe');
    }

    public function testNewPasswordIsSavedTheLinkWorksOnceAndTheOwnerIsWarned(): void
    {
        $user = $this->createUser('fred@exemple.fr');
        $link = $this->link('fred@exemple.fr');

        $this->choosePassword($link, self::NEW_PASSWORD, self::NEW_PASSWORD);

        self::assertResponseRedirects('/connexion');
        $fresh = $this->reload($user);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($fresh, self::NEW_PASSWORD));
        self::assertFalse($hasher->isPasswordValid($fresh, self::OLD_PASSWORD));
        self::assertCount(0, $this->entityManager->getRepository(ResetPasswordRequest::class)->findAll());

        self::assertQueuedEmailCount(1);
        self::assertEmailSubjectContains(self::getMailerMessage(), 'a été modifié');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.auth__notice', 'mot de passe a été modifié');

        // Le même lien ne sert plus
        $this->client->request('GET', $link);
        $this->client->followRedirect();
        self::assertResponseRedirects('/mot-de-passe-oublie');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.form__message', 'a expiré ou a déjà servi');
    }

    public function testDifferentConfirmationIsRefused(): void
    {
        $this->createUser('fred@exemple.fr');

        $this->choosePassword($this->link('fred@exemple.fr'), self::NEW_PASSWORD, 'Autre-Mot-De-Passe-2027!');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#nouveau_mot_de_passe_confirmPassword_error', 'ne correspondent pas');
    }

    public function testWeakPasswordIsRefused(): void
    {
        $this->createUser('fred@exemple.fr');

        $this->choosePassword($this->link('fred@exemple.fr'), 'motdepasse', 'motdepasse');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#nouveau_mot_de_passe_plainPassword_error', 'Au moins 12 caractères');
    }

    public function testInventedLinkIsRefused(): void
    {
        $this->client->request('GET', '/mot-de-passe-oublie/nouveau/abcdefghijklmnopqrstuvwxyz0123456789ABCD');
        $this->client->followRedirect();

        self::assertResponseRedirects('/mot-de-passe-oublie');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.form__message', 'a expiré ou a déjà servi');
    }

    public function testPageWithoutLinkIsRefused(): void
    {
        $this->client->request('GET', '/mot-de-passe-oublie/nouveau');

        self::assertResponseRedirects('/mot-de-passe-oublie');
    }

    public function testResetConfirmsAnAddressNotYetConfirmed(): void
    {
        $user = $this->createUser('fred@exemple.fr', verified: false);

        $this->choosePassword($this->link('fred@exemple.fr'), self::NEW_PASSWORD, self::NEW_PASSWORD);

        self::assertResponseRedirects('/connexion');
        self::assertTrue($this->reload($user)->isEmailVerified());
    }

    private function requestLink(string $email): void
    {
        $crawler = $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submit($crawler->selectButton('Recevoir le lien')->form(['mot_de_passe_oublie[email]' => $email]));
    }

    /**
     * Demande un lien et le récupère dans l'e-mail envoyé.
     */
    private function link(string $email): string
    {
        $this->requestLink($email);
        $body = (string) self::getMailerMessage()?->getHtmlBody();

        self::assertSame(1, preg_match('#http://localhost(/mot-de-passe-oublie/nouveau/[A-Za-z0-9]+)#', $body, $match));

        return $match[1];
    }

    private function choosePassword(string $link, string $password, string $confirmation): void
    {
        $this->client->request('GET', $link);
        $crawler = $this->client->followRedirect();

        $this->client->submit($crawler->selectButton('Enregistrer le mot de passe')->form([
            'nouveau_mot_de_passe[plainPassword]' => $password,
            'nouveau_mot_de_passe[confirmPassword]' => $confirmation,
        ]));
    }

    private function createUser(string $email, bool $verified = true): User
    {
        $user = new User($email, 'Fred');
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::OLD_PASSWORD));

        if ($verified) {
            $user->markEmailAsVerified();
        }

        $this->entityManager->persist($user);
        $this->entityManager->persist(new Profile($user));
        $this->entityManager->flush();

        return $user;
    }

    private function reload(User $user): User
    {
        $this->entityManager->clear();

        return $this->entityManager->find(User::class, $user->getId()) ?? self::fail('Compte introuvable.');
    }

    private function purge(): void
    {
        foreach ([ResetPasswordRequest::class, Profile::class, User::class] as $class) {
            $this->entityManager->createQuery('DELETE FROM '.$class)->execute();
        }
    }
}
