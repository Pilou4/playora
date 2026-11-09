<?php

declare(strict_types=1);

namespace App\Twig;

use App\Avatar\AvatarCatalog;
use App\Service\CurrentPlayer;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * player_avatar_url() : image de l'avatar du joueur connecté (menu du compte), ou null.
 * avatar_version() : version des dessins, ajoutée aux adresses des images (le navigateur reprend les nouveaux dessins).
 */
final class AccountExtension extends AbstractExtension
{
    public function __construct(
        private readonly CurrentPlayer $player,
        private readonly UrlGeneratorInterface $urls,
        private readonly AvatarCatalog $catalog,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('player_avatar_url', $this->avatarUrl(...)),
            new TwigFunction('avatar_version', $this->catalog->version(...)),
        ];
    }

    public function avatarUrl(): ?string
    {
        $code = $this->player->avatarCode();

        return null === $code ? null : $this->urls->generate('app_avatar_face', ['code' => $code, 'v' => $this->catalog->version()]);
    }
}
