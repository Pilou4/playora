<?php

declare(strict_types=1);

namespace App\Controller;

use App\Avatar\AvatarCatalog;
use App\Avatar\AvatarRenderer;
use App\Entity\User;
use App\Repository\ProfileRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AvatarController extends AbstractController
{
    /**
     * L'image d'un avatar. L'adresse contient le code complet : elle ne change jamais
     * de contenu, le navigateur peut donc la garder un an.
     */
    #[Route('/avatars/{code}.svg', name: 'app_avatar_image', requirements: ['code' => AvatarCatalog::CODE_PATTERN], methods: ['GET'])]
    public function image(string $code, AvatarRenderer $renderer): Response
    {
        return $this->svg($renderer->render($code));
    }

    /**
     * Le visage seul, en carré : pour les ronds (menu du compte, salons, table).
     */
    #[Route('/avatars/{code}-visage.svg', name: 'app_avatar_face', requirements: ['code' => AvatarCatalog::CODE_PATTERN], methods: ['GET'])]
    public function face(string $code, AvatarRenderer $renderer): Response
    {
        return $this->svg($renderer->render($code, face: true));
    }

    private function svg(?string $svg): Response
    {
        $svg ??= throw $this->createNotFoundException();

        $response = new Response($svg, Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setImmutable();

        return $response;
    }

    #[Route('/mon-profil/avatar', name: 'app_profile_avatar', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function edit(ProfileRepository $profiles, AvatarCatalog $catalog): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $code = $profiles->findOneByUser($user)?->getAvatar();
        $code = null !== $code && $catalog->isValid($code) ? $code : null;

        return $this->render('profile/avatar.html.twig', [
            'code' => $code,
            // Sans avatar, l'aperçu part du premier de la galerie
            'preview' => $code ?? $catalog->gallery()[0],
        ]);
    }
}
