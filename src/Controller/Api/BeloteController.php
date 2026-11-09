<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\BidRequest;
use App\Dto\PlayCardRequest;
use App\Entity\BeloteGame;
use App\Entity\User;
use App\Game\Belote\ActionResult;
use App\Game\Belote\BeloteException;
use App\Game\Belote\Card;
use App\Service\BeloteTable;
use App\Service\BeloteView;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API d'une partie de belote. Chaque réponse contient ce qui s'est passé (« events », pour les
 * animations, robots compris) et la nouvelle vue du joueur : pas besoin d'attendre Mercure.
 */
#[Route('/api/v1/jeux/belote/parties/{id}', requirements: ['id' => '\d+'], format: 'json')]
#[IsGranted('ROLE_USER', message: 'Connectez-vous pour jouer.', statusCode: Response::HTTP_UNAUTHORIZED)]
final class BeloteController extends AbstractController
{
    public function __construct(
        private readonly BeloteTable $table,
        private readonly BeloteView $view,
        private readonly RateLimiterFactoryInterface $beloteActionsLimiter,
    ) {
    }

    /**
     * Vue du joueur ; avec ?depuis=N, les événements du dernier coup s'il vient juste après la version N.
     */
    #[Route('', name: 'api_belote_game', methods: ['GET'])]
    public function show(#[MapEntity(id: 'id')] BeloteGame $game, Request $request): JsonResponse
    {
        $position = $this->position($game);
        $since = $request->query->getInt('depuis');
        $events = $since > 0 && $since === $game->getVersion() - 1 ? $this->view->events($game->getLastEvents(), $position) : [];

        return $this->json(['events' => $events, 'view' => $this->view->forPlayer($game, $position)], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/encheres', name: 'api_belote_bid', methods: ['POST'])]
    public function bid(#[MapEntity(id: 'id')] BeloteGame $game, Request $request, #[MapRequestPayload] BidRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user): ActionResult => $this->table->bid($user, $game, $payload->take, $payload->suit()));
    }

    #[Route('/cartes', name: 'api_belote_play', methods: ['POST'])]
    public function play(#[MapEntity(id: 'id')] BeloteGame $game, Request $request, #[MapRequestPayload] PlayCardRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user): ActionResult => $this->table->play($user, $game, Card::fromCode($payload->card)));
    }

    /**
     * Le temps du joueur dont c'est le tour est écoulé : le serveur joue à sa place.
     */
    #[Route('/temps-ecoule', name: 'api_belote_timeout', methods: ['POST'])]
    public function timeout(#[MapEntity(id: 'id')] BeloteGame $game, Request $request): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user): ActionResult => $this->table->timeout($user, $game));
    }

    /**
     * @param \Closure(User): ActionResult $action
     */
    private function run(BeloteGame $game, \Closure $action): JsonResponse
    {
        $position = $this->position($game);

        try {
            $result = $action($this->player());
        } catch (BeloteException $exception) {
            return $this->problem(Response::HTTP_CONFLICT, $exception->getMessage(), $this->view->forPlayer($game, $position));
        }

        return $this->json([
            'events' => $this->view->events($result->events, $position),
            'view' => $this->view->forPlayer($game, $position),
        ], headers: ['Cache-Control' => 'no-store']);
    }

    private function guard(Request $request): ?JsonResponse
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'Requête refusée.');
        }

        if (!$this->beloteActionsLimiter->create((string) $this->player()->getId())->consume()->isAccepted()) {
            return $this->problem(Response::HTTP_TOO_MANY_REQUESTS, 'Trop d’actions d’affilée : patientez quelques secondes.');
        }

        return null;
    }

    /**
     * Seuls les joueurs assis à la table voient la partie.
     */
    private function position(BeloteGame $game): int
    {
        return $this->table->positionOf($this->player(), $game) ?? throw $this->createAccessDeniedException('Vous n’êtes pas à cette table.');
    }

    /**
     * @param array<string, mixed>|null $view
     */
    private function problem(int $status, string $detail, ?array $view = null): JsonResponse
    {
        $body = ['status' => $status, 'title' => Response::$statusTexts[$status] ?? 'Error', 'detail' => $detail];

        if (null !== $view) {
            $body['view'] = $view;
        }

        return new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
    }

    private function player(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
