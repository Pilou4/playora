<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\HuitPlayRequest;
use App\Entity\HuitGame;
use App\Entity\User;
use App\Game\Huit\HuitException;
use App\Service\HuitTable;
use App\Service\HuitView;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API d'une partie de 8 américain (2 à 6 joueurs). Chaque réponse contient la nouvelle vue du joueur.
 */
#[Route('/api/v1/jeux/huit-americain/parties/{id}', requirements: ['id' => '\d+'], format: 'json')]
#[IsGranted('ROLE_USER', message: 'Connectez-vous pour jouer.', statusCode: Response::HTTP_UNAUTHORIZED)]
final class HuitApiController extends AbstractGameApiController
{
    public function __construct(
        private readonly HuitTable $table,
        private readonly HuitView $view,
    ) {
    }

    #[Route('', name: 'api_huit_game', methods: ['GET'])]
    public function show(#[MapEntity(id: 'id')] HuitGame $game): JsonResponse
    {
        return $this->json(['view' => $this->view->forPlayer($game, $this->position($game))], headers: ['Cache-Control' => 'no-store']);
    }

    /** Poser une carte : {"card": "pique-8-1", "suit": "coeur", "announce": false}. */
    #[Route('/cartes', name: 'api_huit_play', methods: ['POST'])]
    public function play(#[MapEntity(id: 'id')] HuitGame $game, Request $request, #[MapRequestPayload] HuitPlayRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->play($user, $game, $payload->card, $payload->suit, $payload->announce));
    }

    /** Piocher (les cartes de pénalité d'un coup, ou une carte). */
    #[Route('/pioche', name: 'api_huit_draw', methods: ['POST'])]
    public function draw(#[MapEntity(id: 'id')] HuitGame $game, Request $request): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->draw($user, $game));
    }

    /** Passer après avoir pioché une carte qu'on ne pose pas. */
    #[Route('/passe', name: 'api_huit_pass', methods: ['POST'])]
    public function pass(#[MapEntity(id: 'id')] HuitGame $game, Request $request): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->pass($user, $game));
    }

    /** Le temps du joueur dont c'est le tour est écoulé : le serveur joue pour lui. */
    #[Route('/temps-ecoule', name: 'api_huit_timeout', methods: ['POST'])]
    public function timeout(#[MapEntity(id: 'id')] HuitGame $game, Request $request): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->timeout($user, $game));
    }

    /** Le joueur quitte la partie : un robot le remplace jusqu'à la fin, la partie lui est comptée perdue. */
    #[Route('/abandon', name: 'api_huit_leave', methods: ['POST'])]
    public function leave(#[MapEntity(id: 'id')] HuitGame $game, Request $request): Response
    {
        if (null !== $response = $this->guard($request)) {
            return $response;
        }

        $this->position($game);

        try {
            $this->table->abandon($this->player(), $game);
        } catch (HuitException $exception) {
            return $this->problem(Response::HTTP_CONFLICT, $exception->getMessage());
        }

        return new Response(status: Response::HTTP_NO_CONTENT, headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * @param \Closure(User): void $action
     */
    private function run(HuitGame $game, \Closure $action): JsonResponse
    {
        $position = $this->position($game);

        try {
            $action($this->player());
        } catch (HuitException $exception) {
            return $this->problem(Response::HTTP_CONFLICT, $exception->getMessage(), $this->view->forPlayer($game, $position));
        }

        return $this->json(['view' => $this->view->forPlayer($game, $position)], headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * Seuls les joueurs assis à la table voient la partie.
     */
    private function position(HuitGame $game): int
    {
        return $this->table->positionOf($this->player(), $game) ?? throw $this->createAccessDeniedException('Vous n’êtes pas à cette table.');
    }
}
