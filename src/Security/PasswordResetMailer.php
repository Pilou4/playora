<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

/**
 * E-mails du mot de passe oublié (envoyés par la file d'attente Messenger).
 * Les adresses sont calculées pendant la requête : le worker n'a pas à connaître le site.
 */
final readonly class PasswordResetMailer
{
    public function __construct(
        private MailerInterface $mailer,
    ) {
    }

    public function sendResetLink(User $user, string $resetUrl): void
    {
        $this->mailer->send((new TemplatedEmail())
            ->to($user->getEmail())
            ->subject('Choisissez un nouveau mot de passe')
            ->htmlTemplate('emails/reset_password/link.html.twig')
            ->textTemplate('emails/reset_password/link.txt.twig')
            ->context(['pseudo' => $user->getPseudo(), 'resetUrl' => $resetUrl]));
    }

    public function sendPasswordChanged(User $user, string $forgotUrl): void
    {
        $this->mailer->send((new TemplatedEmail())
            ->to($user->getEmail())
            ->subject('Votre mot de passe a été modifié')
            ->htmlTemplate('emails/reset_password/changed.html.twig')
            ->textTemplate('emails/reset_password/changed.txt.twig')
            ->context(['pseudo' => $user->getPseudo(), 'forgotUrl' => $forgotUrl]));
    }
}
