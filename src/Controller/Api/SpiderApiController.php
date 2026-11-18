<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\SpiderDealRequest;
use App\Dto\SpiderMoveRequest;
use App\Entity\PatienceGame;
use App\Entity\User;
use App\Enum\Game;
use App\Service\PatienceTable;
use App\Service\SpiderTable;
use App\Service\SpiderView;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API d'une partie de Spider (débutant, Spider ou Pro ; 2 à 5 joueurs). Chaque réponse contient la nouvelle vue du joueur.
 */
#[Route('/api/v1/jeux/spider/parties/{id}', requirements: ['id' => '\d+'], format: 'json')]
#[IsGranted('ROLE_USER', message: 'Connectez-vous pour jouer.', statusCode: Response::HTTP_UNAUTHORIZED)]
final class SpiderApiController extends AbstractPatienceApiController
{
    public function __construct(
        PatienceTable $patience,
        private readonly SpiderTable $table,
        private readonly SpiderView $spiderView,
    ) {
        parent::__construct($patience);
    }

    #[Route('', name: 'api_spider_game', methods: ['GET'])]
    public function show(#[MapEntity(id: 'id')] PatienceGame $game): JsonResponse
    {
        return $this->showGame($game);
    }

    /** Déplacer une carte : {"card": "pique-9-3", "to": "colonne-4"} ou {"card": "coeur-7-1", "to": "joker-0"}. */
    #[Route('/deplacements', name: 'api_spider_move', methods: ['POST'])]
    public function move(#[MapEntity(id: 'id')] PatienceGame $game, Request $request, #[MapRequestPayload] SpiderMoveRequest $payload): JsonResponse
    {
        return $this->play($game, $request, fn (User $user) => $this->table->move($user, $game, $payload->card, $payload->to));
    }

    /** Distribuer une pile talon : {"pile": 2}. */
    #[Route('/talon', name: 'api_spider_deal', methods: ['POST'])]
    public function deal(#[MapEntity(id: 'id')] PatienceGame $game, Request $request, #[MapRequestPayload] SpiderDealRequest $payload): JsonResponse
    {
        return $this->play($game, $request, fn (User $user) => $this->table->deal($user, $game, $payload->pile));
    }

    /** Bouton « Annuler » : le coup précédent, contre des points (50, ou 500 au Pro). */
    #[Route('/retour', name: 'api_spider_undo', methods: ['POST'])]
    public function undo(#[MapEntity(id: 'id')] PatienceGame $game, Request $request): JsonResponse
    {
        return $this->play($game, $request, fn (User $user) => $this->table->undo($user, $game));
    }

    /** Bouton « Arrêter » : la grille s'arrête pour ce joueur, avec son score du moment. */
    #[Route('/arret', name: 'api_spider_stop', methods: ['POST'])]
    public function stop(#[MapEntity(id: 'id')] PatienceGame $game, Request $request): JsonResponse
    {
        return $this->stopGame($game, $request);
    }

    /** Le temps d'une grille est écoulé : le serveur la ferme (et termine la partie si toutes les grilles sont finies). */
    #[Route('/temps-ecoule', name: 'api_spider_timeout', methods: ['POST'])]
    public function timeout(#[MapEntity(id: 'id')] PatienceGame $game, Request $request): JsonResponse
    {
        return $this->timeoutGame($game, $request);
    }

    /** Le joueur quitte sa partie : sa grille est close au score du moment (sans bonus de temps). */
    #[Route('/abandon', name: 'api_spider_leave', methods: ['POST'])]
    public function leave(#[MapEntity(id: 'id')] PatienceGame $game, Request $request): Response
    {
        return $this->leaveGame($game, $request);
    }

    protected function accepts(Game $game): bool
    {
        return $game->isSpider();
    }

    protected function view(PatienceGame $game, int $me): array
    {
        return $this->spiderView->forPlayer($game, $me);
    }
}
