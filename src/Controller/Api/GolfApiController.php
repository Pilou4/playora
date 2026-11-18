<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\GolfPlaceRequest;
use App\Entity\GolfGame;
use App\Entity\User;
use App\Game\Golf\GolfException;
use App\Service\GolfTable;
use App\Service\GolfView;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API d'une partie de Golf (2 à 6 joueurs). Chaque réponse contient la nouvelle vue du joueur.
 */
#[Route('/api/v1/jeux/golf/parties/{id}', requirements: ['id' => '\d+'], format: 'json')]
#[IsGranted('ROLE_USER', message: 'Connectez-vous pour jouer.', statusCode: Response::HTTP_UNAUTHORIZED)]
final class GolfApiController extends AbstractGameApiController
{
    public function __construct(
        private readonly GolfTable $table,
        private readonly GolfView $view,
    ) {
    }

    #[Route('', name: 'api_golf_game', methods: ['GET'])]
    public function show(#[MapEntity(id: 'id')] GolfGame $game): JsonResponse
    {
        return $this->json(['view' => $this->view->forPlayer($game, $this->position($game))], headers: ['Cache-Control' => 'no-store']);
    }

    /** Début du trou : retourner une de ses 2 premières cartes : {"index": 0}. */
    #[Route('/retourner', name: 'api_golf_reveal', methods: ['POST'])]
    public function reveal(#[MapEntity(id: 'id')] GolfGame $game, Request $request, #[MapRequestPayload] GolfPlaceRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->reveal($user, $game, (int) $payload->index));
    }

    /** Piocher une carte (à échanger, ou à jeter en retournant une carte cachée). */
    #[Route('/pioche', name: 'api_golf_draw', methods: ['POST'])]
    public function draw(#[MapEntity(id: 'id')] GolfGame $game, Request $request): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->draw($user, $game));
    }

    /** Prendre la carte de la défausse et l'échanger contre la carte à cette place : {"index": 4}. */
    #[Route('/defausse/prendre', name: 'api_golf_take_discard', methods: ['POST'])]
    public function takeDiscard(#[MapEntity(id: 'id')] GolfGame $game, Request $request, #[MapRequestPayload] GolfPlaceRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->takeDiscard($user, $game, (int) $payload->index));
    }

    /** Échanger la carte piochée contre la carte à cette place : {"index": 4}. */
    #[Route('/echanger', name: 'api_golf_swap', methods: ['POST'])]
    public function swap(#[MapEntity(id: 'id')] GolfGame $game, Request $request, #[MapRequestPayload] GolfPlaceRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->swap($user, $game, (int) $payload->index));
    }

    /** Jeter la carte piochée et retourner la carte cachée à cette place : {"index": 4}. */
    #[Route('/jeter', name: 'api_golf_discard', methods: ['POST'])]
    public function discard(#[MapEntity(id: 'id')] GolfGame $game, Request $request, #[MapRequestPayload] GolfPlaceRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->discardAndReveal($user, $game, (int) $payload->index));
    }

    /** Le temps du joueur dont c'est le tour est écoulé : le serveur joue pour lui. */
    #[Route('/temps-ecoule', name: 'api_golf_timeout', methods: ['POST'])]
    public function timeout(#[MapEntity(id: 'id')] GolfGame $game, Request $request): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->timeout($user, $game));
    }

    /** Le joueur quitte la partie : un robot le remplace jusqu'à la fin, la partie lui est comptée perdue. */
    #[Route('/abandon', name: 'api_golf_leave', methods: ['POST'])]
    public function leave(#[MapEntity(id: 'id')] GolfGame $game, Request $request): Response
    {
        if (null !== $response = $this->guard($request)) {
            return $response;
        }

        $this->position($game);

        try {
            $this->table->abandon($this->player(), $game);
        } catch (GolfException $exception) {
            return $this->problem(Response::HTTP_CONFLICT, $exception->getMessage());
        }

        return new Response(status: Response::HTTP_NO_CONTENT, headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * @param \Closure(User): void $action
     */
    private function run(GolfGame $game, \Closure $action): JsonResponse
    {
        $position = $this->position($game);

        try {
            $action($this->player());
        } catch (GolfException $exception) {
            return $this->problem(Response::HTTP_CONFLICT, $exception->getMessage(), $this->view->forPlayer($game, $position));
        }

        return $this->json(['view' => $this->view->forPlayer($game, $position)], headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * Seuls les joueurs assis à la table voient la partie.
     */
    private function position(GolfGame $game): int
    {
        return $this->table->positionOf($this->player(), $game) ?? throw $this->createAccessDeniedException('Vous n’êtes pas à cette table.');
    }
}
