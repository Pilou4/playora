<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Vérifications faites APRÈS le contrôle du mot de passe : un inconnu ne peut pas
 * savoir si une adresse est inscrite, confirmée ou supprimée.
 */
final class UserChecker implements UserCheckerInterface
{
    public const string BAD_CREDENTIALS = 'Adresse e-mail ou mot de passe incorrect.';
    public const string EMAIL_NOT_VERIFIED = 'Adresse pas encore confirmée : cliquez sur le lien reçu par e-mail.';

    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }

        if ($user->isDeleted()) {
            throw new CustomUserMessageAccountStatusException(self::BAD_CREDENTIALS);
        }

        if (!$user->isEmailVerified()) {
            throw new CustomUserMessageAccountStatusException(self::EMAIL_NOT_VERIFIED);
        }
    }
}
