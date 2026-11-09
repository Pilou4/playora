<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Avatar\AvatarCatalog;
use App\Dto\UpdateAvatarRequest;
use App\Entity\Profile;
use App\Entity\User;
use App\Repository\ProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Avatars : les pièces (pour composer, sur le site comme dans l'application mobile)
 * et l'enregistrement de l'avatar du joueur connecté.
 */
#[Route('/api/v1', format: 'json')]
final class AvatarController extends AbstractController
{
    #[Route('/avatars/pieces', name: 'api_avatar_pieces', methods: ['GET'])]
    public function pieces(AvatarCatalog $catalog): JsonResponse
    {
        $response = JsonResponse::fromJsonString($catalog->json());
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }

    #[Route('/moi/avatar', name: 'api_avatar_update', methods: ['PUT'])]
    #[IsGranted('ROLE_USER', message: 'Connectez-vous pour choisir un avatar.', statusCode: Response::HTTP_UNAUTHORIZED)]
    public function update(
        Request $request,
        #[MapRequestPayload] UpdateAvatarRequest $payload,
        AvatarCatalog $catalog,
        ProfileRepository $profiles,
        EntityManagerInterface $entityManager,
        RateLimiterFactoryInterface $avatarUpdateLimiter,
    ): JsonResponse {
        $user = $this->getUser();
        \assert($user instanceof User);

        if (!$this->isCsrfTokenValid('submit', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'Requête refusée.');
        }

        if (!$avatarUpdateLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            return $this->problem(Response::HTTP_TOO_MANY_REQUESTS, 'Trop d’enregistrements d’affilée : patientez quelques secondes.');
        }

        if (null !== $payload->code && !$catalog->isValid($payload->code)) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'Cet avatar n’existe pas.');
        }

        $profile = $profiles->findOneByUser($user);
        if (null === $profile) {
            $profile = new Profile($user);
            $entityManager->persist($profile);
        }

        $profile->setAvatar($payload->code);
        $entityManager->flush();

        return new JsonResponse([
            'code' => $payload->code,
            'url' => null === $payload->code ? null : $this->generateUrl('app_avatar_image', ['code' => $payload->code, 'v' => $catalog->version()]),
            'face' => null === $payload->code ? null : $this->generateUrl('app_avatar_face', ['code' => $payload->code, 'v' => $catalog->version()]),
        ], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    private function problem(int $status, string $detail): JsonResponse
    {
        return new JsonResponse(
            ['status' => $status, 'title' => Response::$statusTexts[$status] ?? 'Error', 'detail' => $detail],
            $status,
            ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store'],
        );
    }
}
