<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Profile;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Inscription sans révéler si une adresse a déjà un compte :
 * la réponse est la même dans tous les cas, seul l'e-mail envoyé change.
 */
final readonly class UserRegistration
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private EmailVerifier $emailVerifier,
        private MailerInterface $mailer,
    ) {
    }

    /**
     * @return User|null le compte créé, ou null si l'adresse était déjà inscrite (ne jamais l'indiquer au visiteur)
     */
    public function register(string $email, string $pseudo, string $plainPassword): ?User
    {
        $user = new User($email, $pseudo);
        // Haché dans tous les cas : le temps de réponse ne trahit pas un compte existant
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        $existing = $this->users->findOneBy(['email' => $user->getEmail()]);

        if (null !== $existing) {
            $this->notifyExistingAccount($existing);

            return null;
        }

        $this->entityManager->persist($user);
        $this->entityManager->persist(new Profile($user));
        $this->entityManager->flush();

        $this->emailVerifier->sendConfirmation($user);

        return $user;
    }

    public function resendConfirmation(string $email): void
    {
        $user = $this->users->findOneBy(['email' => User::normalizeEmail($email)]);

        if (null !== $user && !$user->isDeleted() && !$user->isEmailVerified()) {
            $this->emailVerifier->sendConfirmation($user);
        }
    }

    private function notifyExistingAccount(User $user): void
    {
        if ($user->isDeleted()) {
            return;
        }

        if (!$user->isEmailVerified()) {
            $this->emailVerifier->sendConfirmation($user);

            return;
        }

        $this->mailer->send((new TemplatedEmail())
            ->to($user->getEmail())
            ->subject('Votre compte Playora existe déjà')
            ->htmlTemplate('emails/registration/account_exists.html.twig')
            ->textTemplate('emails/registration/account_exists.txt.twig')
            ->context(['pseudo' => $user->getPseudo()]));
    }
}
