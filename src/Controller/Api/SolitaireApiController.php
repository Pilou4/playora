<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\SolitaireDrawRequest;
use App\Dto\SolitaireMoveRequest;
use App\Entity\PatienceGame;
use App\Entity\User;
use App\Enum\Game;
use App\Service\PatienceTable;
use App\Service\SolitaireTable;
use App\Service\SolitaireView;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API d'une partie de solitaire (2 à 5 joueurs). Chaque réponse contient la nouvelle vue du joueur (sa grille, son temps, son score).
 */
#[Route('/api/v1/jeux/solitaire/parties/{id}', requirements: ['id' => '\d+'], format: 'json')]
#[IsGranted('ROLE_USER', message: 'Connectez-vous pour jouer.', statusCode: Response::HTTP_UNAUTHORIZED)]
final class SolitaireApiController extends AbstractPatienceApiController
{
    public function __construct(
        PatienceTable $patience,
        private readonly SolitaireTable $table,
        private readonly SolitaireView $solitaireView,
    ) {
        parent::__construct($patience);
    }

    #[Route('', name: 'api_solitaire_game', methods: ['GET'])]
    public function show(#[MapEntity(id: 'id')] PatienceGame $game): JsonResponse
    {
        return $this->showGame($game);
    }

    /** Clic sur le talon : une carte est retournée, ou le talon est remis en place. */
    #[Route('/talon', name: 'api_solitaire_draw', methods: ['POST'])]
    public function draw(#[MapEntity(id: 'id')] PatienceGame $game, Request $request, #[MapRequestPayload] SolitaireDrawRequest $payload): JsonResponse
    {
        return $this->play($game, $request, fn (User $user) => $this->table->draw($user, $game, $payload->auto));
    }

    /** Déplacer une carte : {"card": "coeur-as", "to": "famille"} ou {"card": "pique-9", "to": "colonne-3"}. */
    #[Route('/deplacements', name: 'api_solitaire_move', methods: ['POST'])]
    public function move(#[MapEntity(id: 'id')] PatienceGame $game, Request $request, #[MapRequestPayload] SolitaireMoveRequest $payload): JsonResponse
    {
        return $this->play($game, $request, fn (User $user) => $this->table->move($user, $game, $payload->card, $payload->to, $payload->auto));
    }

    /** Bouton « Retour » : le coup précédent, contre 1 000 points. */
    #[Route('/retour', name: 'api_solitaire_undo', methods: ['POST'])]
    public function undo(#[MapEntity(id: 'id')] PatienceGame $game, Request $request): JsonResponse
    {
        return $this->play($game, $request, fn (User $user) => $this->table->undo($user, $game));
    }

    /** Bouton « Arrêter » : la manche s'arrête pour ce joueur, avec un petit bonus pour le temps qui reste. */
    #[Route('/arret', name: 'api_solitaire_stop', methods: ['POST'])]
    public function stop(#[MapEntity(id: 'id')] PatienceGame $game, Request $request): JsonResponse
    {
        return $this->stopGame($game, $request);
    }

    /** Le temps d'une grille est écoulé : le serveur la ferme (et passe à la manche suivante si toutes sont finies). */
    #[Route('/temps-ecoule', name: 'api_solitaire_timeout', methods: ['POST'])]
    public function timeout(#[MapEntity(id: 'id')] PatienceGame $game, Request $request): JsonResponse
    {
        return $this->timeoutGame($game, $request);
    }

    /** Le joueur quitte sa partie : sa grille est close au score du moment (sans bonus de temps). */
    #[Route('/abandon', name: 'api_solitaire_leave', methods: ['POST'])]
    public function leave(#[MapEntity(id: 'id')] PatienceGame $game, Request $request): Response
    {
        return $this->leaveGame($game, $request);
    }

    protected function accepts(Game $game): bool
    {
        return $game->isSolitaire();
    }

    protected function view(PatienceGame $game, int $me): array
    {
        return $this->solitaireView->forPlayer($game, $me);
    }
}
