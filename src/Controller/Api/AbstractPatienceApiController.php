<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\PatienceGame;
use App\Entity\User;
use App\Enum\Game;
use App\Game\Patience\PatienceException;
use App\Service\PatienceTable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Commun aux API des jeux de patience (solitaire, Spider) : vue du joueur, « Arrêter », temps écoulé, abandon,
 * et réponse de chaque coup. Chaque jeu déclare ses routes et ses coups à lui.
 */
abstract class AbstractPatienceApiController extends AbstractGameApiController
{
    public function __construct(
        protected readonly PatienceTable $patience,
    ) {
    }

    /** Le jeu servi par ce contrôleur : une partie d'un autre jeu y est introuvable. */
    abstract protected function accepts(Game $game): bool;

    /**
     * La vue du joueur, grille comprise.
     *
     * @return array<string, mixed>
     */
    abstract protected function view(PatienceGame $game, int $me): array;

    protected function showGame(PatienceGame $game): JsonResponse
    {
        return $this->json(['view' => $this->view($game, $this->position($game))], headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * Un coup du joueur : jeton et limite d'actions vérifiés, puis la nouvelle vue (ou le refus, avec la vue à jour).
     *
     * @param \Closure(User): void $action
     */
    protected function play(PatienceGame $game, Request $request, \Closure $action): JsonResponse
    {
        if (null !== $response = $this->guard($request)) {
            return $response;
        }

        $position = $this->position($game);

        try {
            $action($this->player());
        } catch (PatienceException $exception) {
            return $this->problem(Response::HTTP_CONFLICT, $exception->getMessage(), $this->view($game, $position));
        }

        return $this->json(['view' => $this->view($game, $position)], headers: ['Cache-Control' => 'no-store']);
    }

    protected function stopGame(PatienceGame $game, Request $request): JsonResponse
    {
        return $this->play($game, $request, fn (User $user) => $this->patience->stop($user, $game));
    }

    protected function timeoutGame(PatienceGame $game, Request $request): JsonResponse
    {
        return $this->play($game, $request, fn (User $user) => $this->patience->timeout($user, $game));
    }

    /** Le joueur quitte sa partie : sa grille est close au score du moment (sans bonus de temps). */
    protected function leaveGame(PatienceGame $game, Request $request): Response
    {
        if (null !== $response = $this->guard($request)) {
            return $response;
        }

        $this->position($game);

        try {
            $this->patience->abandon($this->player(), $game);
        } catch (PatienceException $exception) {
            return $this->problem(Response::HTTP_CONFLICT, $exception->getMessage());
        }

        return new Response(status: Response::HTTP_NO_CONTENT, headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * Seuls les joueurs assis à la table voient la partie ; une partie d'un autre jeu est introuvable ici.
     */
    protected function position(PatienceGame $game): int
    {
        if (!$this->accepts($game->getRoom()->getGame())) {
            throw $this->createNotFoundException();
        }

        return $this->patience->positionOf($this->player(), $game) ?? throw $this->createAccessDeniedException('Vous n’êtes pas à cette table.');
    }
}
