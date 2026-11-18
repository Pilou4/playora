<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Commun aux API des parties (belote, tarot, solitaire) : contrôle de chaque action (jeton du formulaire,
 * limite d'actions par joueur) et erreurs au même format partout (application/problem+json).
 */
abstract class AbstractGameApiController extends AbstractController
{
    private RateLimiterFactoryInterface $actionsLimiter;

    #[Required]
    public function setActionsLimiter(RateLimiterFactoryInterface $beloteActionsLimiter): void
    {
        $this->actionsLimiter = $beloteActionsLimiter;
    }

    /**
     * Avant chaque action : jeton CSRF valide et pas trop d'actions d'affilée. Renvoie l'erreur à répondre, ou null.
     */
    protected function guard(Request $request): ?JsonResponse
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'Requête refusée.');
        }

        if (!$this->actionsLimiter->create((string) $this->player()->getId())->consume()->isAccepted()) {
            return $this->problem(Response::HTTP_TOO_MANY_REQUESTS, 'Trop d’actions d’affilée : patientez quelques secondes.');
        }

        return null;
    }

    /**
     * Erreur pour le joueur (message en français), avec sa vue à jour quand le coup a été refusé.
     *
     * @param array<string, mixed>|null $view
     */
    protected function problem(int $status, string $detail, ?array $view = null): JsonResponse
    {
        $body = ['status' => $status, 'title' => Response::$statusTexts[$status] ?? 'Error', 'detail' => $detail];

        if (null !== $view) {
            $body['view'] = $view;
        }

        return new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
    }

    /**
     * Le joueur connecté (les routes de ces contrôleurs exigent ROLE_USER).
     */
    protected function player(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
