<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Profile;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final class RegistrationControllerTest extends WebTestCase
{
    private const string STRONG_PASSWORD = 'Belote-Atout-Coeur-2026!';

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
        $this->entityManager->createQuery('DELETE FROM '.Profile::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testPageIsDisplayed(): void
    {
        $crawler = $this->client->request('GET', '/inscription');

        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Créer un compte');
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorExists('main#inscription');
        self::assertSame('noindex', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('page', $crawler->filter('.site-header__account a[href="/inscription"]')->attr('aria-current'));
    }

    public function testEveryFieldHasALabelAndAnAutocomplete(): void
    {
        $crawler = $this->client->request('GET', '/inscription');
        $inputs = $crawler->filter('form.form input:not([type="hidden"])');

        self::assertCount(3, $inputs);
        $inputs->each(static function (Crawler $input) use ($crawler): void {
            self::assertCount(1, $crawler->filter('label[for="'.$input->attr('id').'"]'));
            self::assertNotEmpty($input->attr('autocomplete'));
        });
    }

    public function testPasswordToggleControlsThePasswordField(): void
    {
        $crawler = $this->client->request('GET', '/inscription');
        $toggle = $crawler->filter('.field__toggle');

        self::assertCount(1, $toggle);
        self::assertSame('password', $crawler->filter('#'.$toggle->attr('aria-controls'))->attr('type'));
    }

    public function testRegistrationCreatesAnUnverifiedAccountAndSendsTheLink(): void
    {
        $this->submit('fred@exemple.fr', 'Fred', self::STRONG_PASSWORD);

        self::assertResponseRedirects('/inscription/verifiez-vos-emails');

        $user = $this->users()->findOneBy(['email' => 'fred@exemple.fr']);
        self::assertNotNull($user);
        self::assertFalse($user->isEmailVerified());
        self::assertNotSame(self::STRONG_PASSWORD, $user->getPassword());
        self::assertNotNull($this->entityManager->getRepository(Profile::class)->findOneBy(['user' => $user]));

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'To', 'fred@exemple.fr');
        self::assertEmailSubjectContains($email, 'Confirmez votre adresse');
        self::assertEmailHtmlBodyContains($email, '/inscription/verification?');

        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'fred@exemple.fr');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function weakPasswords(): iterable
    {
        yield 'trop court' => ['Court-1a!', 'Au moins 12 caractères.'];
        yield 'sans majuscule' => ['belote-atout-2026!', 'Ajoutez une majuscule.'];
        yield 'sans minuscule' => ['BELOTE-ATOUT-2026!', 'Ajoutez une minuscule.'];
        yield 'sans chiffre' => ['Belote-Atout-Coeur!', 'Ajoutez un chiffre.'];
        yield 'sans caractère spécial' => ['BeloteAtoutCoeur2026', 'Ajoutez un caractère spécial'];
    }

    #[DataProvider('weakPasswords')]
    public function testWeakPasswordIsRefused(string $password, string $expectedMessage): void
    {
        $this->submit('fred@exemple.fr', 'Fred', $password);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field--error .field__error', $expectedMessage);
        self::assertNull($this->users()->findOneBy(['email' => 'fred@exemple.fr']));
    }

    public function testErrorPlacesAreReservedAndShowOneMessagePerField(): void
    {
        $crawler = $this->client->request('GET', '/inscription');

        self::assertCount(3, $crawler->filter('.field__error'));
        self::assertSelectorExists('.form__message');
        self::assertSelectorNotExists('.form__message--visible');

        $this->submit('', '', 'court');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorCount(3, '.field--error');
        self::assertSelectorCount(3, '.field__error');
        self::assertSelectorTextSame('#registration_form_plainPassword_error', 'Au moins 12 caractères.');
    }

    public function testPredictablePasswordIsRefused(): void
    {
        $this->submit('fred@exemple.fr', 'Fred', 'Aaaaaaaaaaa1!');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field--error .field__error', 'trop facile à deviner');
    }

    public function testInvalidPseudoIsRefused(): void
    {
        $this->submit('fred@exemple.fr', 'Fr ed<', self::STRONG_PASSWORD);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field--error .field__error', 'Lettres, chiffres et . _ - uniquement.');
    }

    public function testTakenPseudoIsRefused(): void
    {
        $this->createUser('autre@exemple.fr', 'Élodie');

        $this->submit('fred@exemple.fr', 'elodie', self::STRONG_PASSWORD);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field--error .field__error', 'Ce pseudo est déjà pris.');
    }

    public function testExistingEmailGetsTheSameAnswerAndOnlyTheOwnerIsWarned(): void
    {
        $this->createUser('fred@exemple.fr', 'Fred', verified: true);

        $this->submit('FRED@exemple.fr', 'NouveauFred', self::STRONG_PASSWORD);

        self::assertResponseRedirects('/inscription/verifiez-vos-emails');
        self::assertNull($this->users()->findOneBy(['pseudo' => 'NouveauFred']));
        self::assertQueuedEmailCount(1);
        self::assertEmailSubjectContains(self::getMailerMessage(), 'existe déjà');
    }

    public function testFormWithoutOriginIsRefused(): void
    {
        // Ce que le navigateur envoie quand un autre site soumet le formulaire
        $this->client->setServerParameter('HTTP_SEC_FETCH_SITE', 'cross-site');
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://site-pirate.example');

        $this->submit('fred@exemple.fr', 'Fred', self::STRONG_PASSWORD);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form__message--visible', 'Le formulaire a expiré');
        self::assertNull($this->users()->findOneBy(['email' => 'fred@exemple.fr']));
    }

    public function testRegistrationsAreLimitedPerAddress(): void
    {
        foreach (['a', 'b', 'c'] as $letter) {
            $this->submit($letter.'@exemple.fr', 'Joueur'.$letter, self::STRONG_PASSWORD);
            self::assertResponseRedirects('/inscription/verifiez-vos-emails');
        }

        $this->submit('d@exemple.fr', 'Joueurd', self::STRONG_PASSWORD);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form__message--visible', 'Trop de tentatives');
    }

    public function testValidLinkConfirmsTheAddress(): void
    {
        $user = $this->createUser('fred@exemple.fr', 'Fred');

        $this->client->request('GET', $this->signedUrl($user));

        self::assertResponseRedirects('/inscription/confirmee');
        $this->entityManager->refresh($user);
        self::assertTrue($user->isEmailVerified());

        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Bienvenue à la table, Fred !');
        // Lien ouvert ailleurs que dans le navigateur de l'inscription : pas de connexion automatique
        self::assertSelectorNotExists('.site-header__pseudo');
    }

    public function testLinkOpenedInTheSameBrowserLogsInAndAsksForAnAvatar(): void
    {
        $this->submit('fred@exemple.fr', 'Fred', self::STRONG_PASSWORD);
        $user = $this->users()->findOneBy(['email' => 'fred@exemple.fr']);

        $this->client->request('GET', $this->signedUrl($user));
        self::assertResponseRedirects('/inscription/confirmee');

        // Connecté : on passe au choix de l'avatar
        $this->client->followRedirect();
        self::assertResponseRedirects('/bienvenue');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Bienvenue Fred ! Choisissez votre avatar');
        self::assertSelectorTextContains('.site-header__pseudo', 'Fred');
    }

    public function testStatusOnlyTellsAboutTheAccountCreatedInThisBrowser(): void
    {
        $this->client->request('GET', '/inscription/statut');
        self::assertSame(['confirmed' => false], json_decode((string) $this->client->getResponse()->getContent(), true));

        $this->submit('fred@exemple.fr', 'Fred', self::STRONG_PASSWORD);
        $this->client->request('GET', '/inscription/statut');
        self::assertSame(['confirmed' => false], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $user = $this->users()->findOneBy(['email' => 'fred@exemple.fr']);
        $user->markEmailAsVerified();
        $this->entityManager->flush();

        $this->client->request('GET', '/inscription/statut');
        self::assertSame(['confirmed' => true], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testWaitingPageLeavesOnceConfirmed(): void
    {
        $this->submit('fred@exemple.fr', 'Fred', self::STRONG_PASSWORD);
        $user = $this->users()->findOneBy(['email' => 'fred@exemple.fr']);
        $user->markEmailAsVerified();
        $this->entityManager->flush();

        $this->client->request('GET', '/inscription/verifiez-vos-emails');

        self::assertResponseRedirects('/inscription/confirmee');
    }

    public function testExistingEmailNeverReportsAConfirmation(): void
    {
        $this->createUser('fred@exemple.fr', 'Fred', verified: true);

        $this->submit('fred@exemple.fr', 'NouveauFred', self::STRONG_PASSWORD);
        $this->client->request('GET', '/inscription/statut');

        self::assertSame(['confirmed' => false], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testModifiedLinkIsRefused(): void
    {
        $user = $this->createUser('fred@exemple.fr', 'Fred');

        $this->client->request('GET', $this->signedUrl($user).'0');

        self::assertResponseRedirects('/inscription/lien-expire');
        $this->entityManager->refresh($user);
        self::assertFalse($user->isEmailVerified());
    }

    public function testExpiredPageSendsANewLink(): void
    {
        $this->createUser('fred@exemple.fr', 'Fred');

        $crawler = $this->client->request('GET', '/inscription/lien-expire');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Recevoir un nouveau lien')->form(['email' => 'fred@exemple.fr']));

        self::assertResponseRedirects('/inscription/verifiez-vos-emails');
        self::assertQueuedEmailCount(1);
    }

    public function testNewLinkForAnUnknownAddressGivesTheSameAnswer(): void
    {
        $crawler = $this->client->request('GET', '/inscription/lien-expire');

        $this->client->submit($crawler->selectButton('Recevoir un nouveau lien')->form(['email' => 'inconnu@exemple.fr']));

        self::assertResponseRedirects('/inscription/verifiez-vos-emails');
        self::assertQueuedEmailCount(0);
    }

    private function submit(string $email, string $pseudo, string $password): void
    {
        $crawler = $this->client->request('GET', '/inscription');

        $this->client->submit($crawler->selectButton('Créer mon compte')->form([
            'registration_form[email]' => $email,
            'registration_form[pseudo]' => $pseudo,
            'registration_form[plainPassword]' => $password,
        ]));
    }

    private function createUser(string $email, string $pseudo, bool $verified = false): User
    {
        $user = new User($email, $pseudo);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::STRONG_PASSWORD));

        if ($verified) {
            $user->markEmailAsVerified();
        }

        $this->entityManager->persist($user);
        $this->entityManager->persist(new Profile($user));
        $this->entityManager->flush();

        return $user;
    }

    private function signedUrl(User $user): string
    {
        return static::getContainer()->get(VerifyEmailHelperInterface::class)
            ->generateSignature('app_verify_email', (string) $user->getId(), $user->getEmail(), ['id' => $user->getId()])
            ->getSignedUrl();
    }

    private function users(): UserRepository
    {
        return $this->entityManager->getRepository(User::class);
    }
}
