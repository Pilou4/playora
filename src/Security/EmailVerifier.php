<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final readonly class EmailVerifier
{
    public const string ROUTE = 'app_verify_email';

    public function __construct(
        private VerifyEmailHelperInterface $helper,
        private MailerInterface $mailer,
    ) {
    }

    public function sendConfirmation(User $user): void
    {
        $signature = $this->helper->generateSignature(
            self::ROUTE,
            (string) $user->getId(),
            $user->getEmail(),
            ['id' => $user->getId()],
        );

        $this->mailer->send((new TemplatedEmail())
            ->to(new Address($user->getEmail(), $user->getPseudo()))
            ->subject('Confirmez votre adresse e-mail')
            ->htmlTemplate('emails/registration/confirmation.html.twig')
            ->textTemplate('emails/registration/confirmation.txt.twig')
            ->context([
                'pseudo' => $user->getPseudo(),
                'signedUrl' => $signature->getSignedUrl(),
            ]));
    }

    /**
     * @throws VerifyEmailExceptionInterface lien expiré, modifié ou destiné à une autre adresse
     */
    public function confirm(Request $request, User $user): void
    {
        $this->helper->validateEmailConfirmationFromRequest($request, (string) $user->getId(), $user->getEmail());

        $user->markEmailAsVerified();
    }
}
