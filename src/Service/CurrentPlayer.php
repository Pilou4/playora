<?php

declare(strict_types=1);

namespace App\Service;

use App\Avatar\AvatarCatalog;
use App\Entity\Profile;
use App\Entity\User;
use App\Repository\ProfileRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Le joueur connecté et son profil, lus une seule fois par requête
 * (utilisés par le menu du compte et par l'obligation de choisir un avatar).
 */
final class CurrentPlayer implements ResetInterface
{
    private ?Profile $profile = null;
    private bool $loaded = false;

    public function __construct(
        private readonly Security $security,
        private readonly ProfileRepository $profiles,
        private readonly AvatarCatalog $catalog,
    ) {
    }

    public function user(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    /**
     * Code de l'avatar du joueur connecté, ou null s'il n'en a pas (ou plus : code d'une pièce retirée).
     */
    public function avatarCode(): ?string
    {
        $code = $this->profile()?->getAvatar();

        return null !== $code && $this->catalog->isValid($code) ? $code : null;
    }

    public function reset(): void
    {
        $this->profile = null;
        $this->loaded = false;
    }

    private function profile(): ?Profile
    {
        if (!$this->loaded) {
            $user = $this->user();
            $this->profile = null === $user ? null : $this->profiles->findOneByUser($user);
            $this->loaded = true;
        }

        return $this->profile;
    }
}
