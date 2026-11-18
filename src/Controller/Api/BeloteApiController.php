<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\AnnounceRequest;
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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API d'une partie de belote. Chaque réponse contient ce qui s'est passé (« events », pour les
 * animations, robots compris) et la nouvelle vue du joueur : pas besoin d'attendre Mercure.
 */
#[Route('/api/v1/jeux/belote/parties/{id}', requirements: ['id' => '\d+'], format: 'json')]
#[IsGranted('ROLE_USER', message: 'Connectez-vous pour jouer.', statusCode: Response::HTTP_UNAUTHORIZED)]
final class BeloteApiController extends AbstractGameApiController
{
    public function __construct(
        private readonly BeloteTable $table,
        private readonly BeloteView $view,
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

    /**
     * Coinche et contrée : annoncer un contrat à une couleur.
     */
    #[Route('/annonce', name: 'api_belote_announce', methods: ['POST'])]
    public function announce(#[MapEntity(id: 'id')] BeloteGame $game, Request $request, #[MapRequestPayload] AnnounceRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user): ActionResult => $this->table->announce($user, $game, $payload->contract, $payload->suit(), $payload->mode()));
    }

    #[Route('/coinche', name: 'api_belote_coinche', methods: ['POST'])]
    public function coinche(#[MapEntity(id: 'id')] BeloteGame $game, Request $request): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user): ActionResult => $this->table->coinche($user, $game));
    }

    #[Route('/surcoinche', name: 'api_belote_surcoinche', methods: ['POST'])]
    public function surcoinche(#[MapEntity(id: 'id')] BeloteGame $game, Request $request): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user): ActionResult => $this->table->surcoinche($user, $game));
    }

    /** Parties avec annonces : déclarer ses annonces (tierce, cinquante, cent, carrés) avant sa première carte. */
    #[Route('/declaration', name: 'api_belote_declare', methods: ['POST'])]
    public function declare(#[MapEntity(id: 'id')] BeloteGame $game, Request $request): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user): ActionResult => $this->table->declare($user, $game));
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
     * Le joueur quitte la partie : un robot le remplace jusqu'à la fin (sans retour), la partie lui est comptée perdue.
     */
    #[Route('/abandon', name: 'api_belote_leave', methods: ['POST'])]
    public function leave(#[MapEntity(id: 'id')] BeloteGame $game, Request $request): Response
    {
        if (null !== $response = $this->guard($request)) {
            return $response;
        }

        $this->position($game);

        try {
            $this->table->abandon($this->player(), $game);
        } catch (BeloteException $exception) {
            return $this->problem(Response::HTTP_CONFLICT, $exception->getMessage());
        }

        return new Response(status: Response::HTTP_NO_CONTENT, headers: ['Cache-Control' => 'no-store']);
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

    /**
     * Seuls les joueurs assis à la table voient la partie.
     */
    private function position(BeloteGame $game): int
    {
        return $this->table->positionOf($this->player(), $game) ?? throw $this->createAccessDeniedException('Vous n’êtes pas à cette table.');
    }
}
