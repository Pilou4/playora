<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\TarotBidRequest;
use App\Dto\TarotDiscardRequest;
use App\Dto\TarotPlayCardRequest;
use App\Dto\TarotPoigneeRequest;
use App\Entity\TarotGame;
use App\Entity\User;
use App\Game\Tarot\TarotActionResult;
use App\Game\Tarot\TarotException;
use App\Service\TarotTable;
use App\Service\TarotView;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API d'une partie de tarot. Chaque réponse contient ce qui s'est passé (« events », robots compris)
 * et la nouvelle vue du joueur.
 */
#[Route('/api/v1/jeux/tarot/parties/{id}', requirements: ['id' => '\d+'], format: 'json')]
#[IsGranted('ROLE_USER', message: 'Connectez-vous pour jouer.', statusCode: Response::HTTP_UNAUTHORIZED)]
final class TarotApiController extends AbstractGameApiController
{
    public function __construct(
        private readonly TarotTable $table,
        private readonly TarotView $view,
    ) {
    }

    /**
     * Vue du joueur ; avec ?depuis=N, les événements du dernier coup s'il vient juste après la version N.
     */
    #[Route('', name: 'api_tarot_game', methods: ['GET'])]
    public function show(#[MapEntity(id: 'id')] TarotGame $game, Request $request): JsonResponse
    {
        $position = $this->position($game);
        $since = $request->query->getInt('depuis');
        $events = $since > 0 && $since === $game->getVersion() - 1 ? $this->view->events($game->getLastEvents(), $position) : [];

        return $this->json(['events' => $events, 'view' => $this->view->forPlayer($game, $position)], headers: ['Cache-Control' => 'no-store']);
    }

    /** Passer ({"contract": null}) ou annoncer une enchère plus forte. */
    #[Route('/encheres', name: 'api_tarot_bid', methods: ['POST'])]
    public function bid(#[MapEntity(id: 'id')] TarotGame $game, Request $request, #[MapRequestPayload] TarotBidRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user): TarotActionResult => $this->table->bid($user, $game, $payload->contract()));
    }

    /** À 5 joueurs : le preneur appelle un Roi ({"card": "coeur-roi"}). */
    #[Route('/appel', name: 'api_tarot_call', methods: ['POST'])]
    public function call(#[MapEntity(id: 'id')] TarotGame $game, Request $request, #[MapRequestPayload] TarotPlayCardRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user): TarotActionResult => $this->table->call($user, $game, $payload->card()));
    }

    /** Petite et garde : les cartes de l'écart du preneur (6, ou 3 à 5 joueurs). */
    #[Route('/ecart', name: 'api_tarot_discard', methods: ['POST'])]
    public function discard(#[MapEntity(id: 'id')] TarotGame $game, Request $request, #[MapRequestPayload] TarotDiscardRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user): TarotActionResult => $this->table->discard($user, $game, $payload->cards()));
    }

    /** Le preneur annonce le chelem avant la première carte. */
    #[Route('/chelem', name: 'api_tarot_chelem', methods: ['POST'])]
    public function chelem(#[MapEntity(id: 'id')] TarotGame $game, Request $request): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user): TarotActionResult => $this->table->chelem($user, $game));
    }

    /** Annoncer une poignée avant de jouer sa première carte. */
    #[Route('/poignee', name: 'api_tarot_poignee', methods: ['POST'])]
    public function poignee(#[MapEntity(id: 'id')] TarotGame $game, Request $request, #[MapRequestPayload] TarotPoigneeRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user): TarotActionResult => $this->table->poignee($user, $game, $payload->poignee()));
    }

    #[Route('/cartes', name: 'api_tarot_play', methods: ['POST'])]
    public function play(#[MapEntity(id: 'id')] TarotGame $game, Request $request, #[MapRequestPayload] TarotPlayCardRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user): TarotActionResult => $this->table->play($user, $game, $payload->card()));
    }

    /** Le temps du joueur dont c'est le tour est écoulé : le serveur joue à sa place. */
    #[Route('/temps-ecoule', name: 'api_tarot_timeout', methods: ['POST'])]
    public function timeout(#[MapEntity(id: 'id')] TarotGame $game, Request $request): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user): TarotActionResult => $this->table->timeout($user, $game));
    }

    /** Le joueur quitte la partie : un robot le remplace jusqu'à la fin, la partie lui est comptée perdue. */
    #[Route('/abandon', name: 'api_tarot_leave', methods: ['POST'])]
    public function leave(#[MapEntity(id: 'id')] TarotGame $game, Request $request): Response
    {
        if (null !== $response = $this->guard($request)) {
            return $response;
        }

        $this->position($game);

        try {
            $this->table->abandon($this->player(), $game);
        } catch (TarotException $exception) {
            return $this->problem(Response::HTTP_CONFLICT, $exception->getMessage());
        }

        return new Response(status: Response::HTTP_NO_CONTENT, headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * @param \Closure(User): TarotActionResult $action
     */
    private function run(TarotGame $game, \Closure $action): JsonResponse
    {
        $position = $this->position($game);

        try {
            $result = $action($this->player());
        } catch (TarotException $exception) {
            return $this->problem(Response::HTTP_CONFLICT, $exception->getMessage(), $this->view->forPlayer($game, $position));
        }

        return $this->json([
            'events' => $this->view->events($result->events, $position),
            'view' => $this->view->forPlayer($game, $position),
        ], headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * Seuls les joueurs assis à la table voient la partie.
     */
    private function position(TarotGame $game): int
    {
        return $this->table->positionOf($this->player(), $game) ?? throw $this->createAccessDeniedException('Vous n’êtes pas à cette table.');
    }
}
