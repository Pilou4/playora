<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\RamiCardRequest;
use App\Dto\RamiMeldRequest;
use App\Entity\RamiGame;
use App\Entity\User;
use App\Game\Rami\RamiException;
use App\Service\RamiTable;
use App\Service\RamiView;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API d'une partie de rami (2 à 6 joueurs). Chaque réponse contient la nouvelle vue du joueur.
 */
#[Route('/api/v1/jeux/rami/parties/{id}', requirements: ['id' => '\d+'], format: 'json')]
#[IsGranted('ROLE_USER', message: 'Connectez-vous pour jouer.', statusCode: Response::HTTP_UNAUTHORIZED)]
final class RamiApiController extends AbstractGameApiController
{
    public function __construct(
        private readonly RamiTable $table,
        private readonly RamiView $view,
    ) {
    }

    #[Route('', name: 'api_rami_game', methods: ['GET'])]
    public function show(#[MapEntity(id: 'id')] RamiGame $game): JsonResponse
    {
        return $this->json(['view' => $this->view->forPlayer($game, $this->position($game))], headers: ['Cache-Control' => 'no-store']);
    }

    /** Prendre la carte du dessus de la pioche. */
    #[Route('/pioche', name: 'api_rami_draw', methods: ['POST'])]
    public function draw(#[MapEntity(id: 'id')] RamiGame $game, Request $request): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->draw($user, $game));
    }

    /** Prendre la carte du dessus de la défausse (à poser pendant ce tour). */
    #[Route('/defausse/prendre', name: 'api_rami_take', methods: ['POST'])]
    public function take(#[MapEntity(id: 'id')] RamiGame $game, Request $request): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->takeDiscard($user, $game));
    }

    /** Rendre la carte prise dans la défausse (tant qu'elle n'est pas posée). */
    #[Route('/defausse/rendre', name: 'api_rami_give_back', methods: ['POST'])]
    public function giveBack(#[MapEntity(id: 'id')] RamiGame $game, Request $request): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->giveBack($user, $game));
    }

    /** Poser des combinaisons : {"groups": [["coeur-7-1", "coeur-8-1", "coeur-9-2"], …]}. */
    #[Route('/poses', name: 'api_rami_meld', methods: ['POST'])]
    public function meld(#[MapEntity(id: 'id')] RamiGame $game, Request $request, #[MapRequestPayload] RamiMeldRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->meld($user, $game, $payload->groups));
    }

    /** Compléter une combinaison posée : {"card": "coeur-10-1", "meld": 2}. */
    #[Route('/ajouts', name: 'api_rami_lay_off', methods: ['POST'])]
    public function layOff(#[MapEntity(id: 'id')] RamiGame $game, Request $request, #[MapRequestPayload] RamiCardRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->layOff($user, $game, $payload->card, $payload->meld));
    }

    /** Reprendre un joker posé en mettant à sa place la carte qu'il remplace : {"card": "pique-valet-2", "meld": 0}. */
    #[Route('/joker', name: 'api_rami_swap_joker', methods: ['POST'])]
    public function swapJoker(#[MapEntity(id: 'id')] RamiGame $game, Request $request, #[MapRequestPayload] RamiCardRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->swapJoker($user, $game, $payload->card, $payload->meld));
    }

    /** Défausser une carte : {"card": "pique-2-1"}. Le tour passe au joueur suivant. */
    #[Route('/defausse', name: 'api_rami_discard', methods: ['POST'])]
    public function discard(#[MapEntity(id: 'id')] RamiGame $game, Request $request, #[MapRequestPayload] RamiCardRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->discard($user, $game, $payload->card));
    }

    /** Le temps du joueur dont c'est le tour est écoulé : le serveur joue pour lui. */
    #[Route('/temps-ecoule', name: 'api_rami_timeout', methods: ['POST'])]
    public function timeout(#[MapEntity(id: 'id')] RamiGame $game, Request $request): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->timeout($user, $game));
    }

    /** Le joueur quitte la partie : un robot le remplace jusqu'à la fin, la partie lui est comptée perdue. */
    #[Route('/abandon', name: 'api_rami_leave', methods: ['POST'])]
    public function leave(#[MapEntity(id: 'id')] RamiGame $game, Request $request): Response
    {
        if (null !== $response = $this->guard($request)) {
            return $response;
        }

        $this->position($game);

        try {
            $this->table->abandon($this->player(), $game);
        } catch (RamiException $exception) {
            return $this->problem(Response::HTTP_CONFLICT, $exception->getMessage());
        }

        return new Response(status: Response::HTTP_NO_CONTENT, headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * @param \Closure(User): void $action
     */
    private function run(RamiGame $game, \Closure $action): JsonResponse
    {
        $position = $this->position($game);

        try {
            $action($this->player());
        } catch (RamiException $exception) {
            return $this->problem(Response::HTTP_CONFLICT, $exception->getMessage(), $this->view->forPlayer($game, $position));
        }

        return $this->json(['view' => $this->view->forPlayer($game, $position)], headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * Seuls les joueurs assis à la table voient la partie.
     */
    private function position(RamiGame $game): int
    {
        return $this->table->positionOf($this->player(), $game) ?? throw $this->createAccessDeniedException('Vous n’êtes pas à cette table.');
    }
}
