<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\PresidentCardsRequest;
use App\Entity\PresidentGame;
use App\Entity\User;
use App\Game\President\PresidentException;
use App\Service\PresidentTable;
use App\Service\PresidentView;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API d'une partie de Président (3 à 7 joueurs). Chaque réponse contient la nouvelle vue du joueur.
 */
#[Route('/api/v1/jeux/president/parties/{id}', requirements: ['id' => '\d+'], format: 'json')]
#[IsGranted('ROLE_USER', message: 'Connectez-vous pour jouer.', statusCode: Response::HTTP_UNAUTHORIZED)]
final class PresidentApiController extends AbstractGameApiController
{
    public function __construct(
        private readonly PresidentTable $table,
        private readonly PresidentView $view,
    ) {
    }

    #[Route('', name: 'api_president_game', methods: ['GET'])]
    public function show(#[MapEntity(id: 'id')] PresidentGame $game): JsonResponse
    {
        return $this->json(['view' => $this->view->forPlayer($game, $this->position($game))], headers: ['Cache-Control' => 'no-store']);
    }

    /** Poser des cartes de même valeur : {"cards": ["pique-9", "coeur-9"]}. */
    #[Route('/cartes', name: 'api_president_play', methods: ['POST'])]
    public function play(#[MapEntity(id: 'id')] PresidentGame $game, Request $request, #[MapRequestPayload] PresidentCardsRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->play($user, $game, $payload->cards));
    }

    /** Passer son tour. */
    #[Route('/passe', name: 'api_president_pass', methods: ['POST'])]
    public function pass(#[MapEntity(id: 'id')] PresidentGame $game, Request $request): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->pass($user, $game));
    }

    /** Échange : le Président ou le Vice-président rend les cartes de son choix : {"cards": ["pique-3", "coeur-4"]}. */
    #[Route('/echange', name: 'api_president_give', methods: ['POST'])]
    public function give(#[MapEntity(id: 'id')] PresidentGame $game, Request $request, #[MapRequestPayload] PresidentCardsRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->give($user, $game, $payload->cards));
    }

    /** Le temps du joueur dont c'est le tour est écoulé : le serveur joue pour lui. */
    #[Route('/temps-ecoule', name: 'api_president_timeout', methods: ['POST'])]
    public function timeout(#[MapEntity(id: 'id')] PresidentGame $game, Request $request): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn (User $user) => $this->table->timeout($user, $game));
    }

    /** Le joueur quitte la partie : un robot le remplace jusqu'à la fin, la partie lui est comptée perdue. */
    #[Route('/abandon', name: 'api_president_leave', methods: ['POST'])]
    public function leave(#[MapEntity(id: 'id')] PresidentGame $game, Request $request): Response
    {
        if (null !== $response = $this->guard($request)) {
            return $response;
        }

        $this->position($game);

        try {
            $this->table->abandon($this->player(), $game);
        } catch (PresidentException $exception) {
            return $this->problem(Response::HTTP_CONFLICT, $exception->getMessage());
        }

        return new Response(status: Response::HTTP_NO_CONTENT, headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * @param \Closure(User): void $action
     */
    private function run(PresidentGame $game, \Closure $action): JsonResponse
    {
        $position = $this->position($game);

        try {
            $action($this->player());
        } catch (PresidentException $exception) {
            return $this->problem(Response::HTTP_CONFLICT, $exception->getMessage(), $this->view->forPlayer($game, $position));
        }

        return $this->json(['view' => $this->view->forPlayer($game, $position)], headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * Seuls les joueurs assis à la table voient la partie.
     */
    private function position(PresidentGame $game): int
    {
        return $this->table->positionOf($this->player(), $game) ?? throw $this->createAccessDeniedException('Vous n’êtes pas à cette table.');
    }
}
