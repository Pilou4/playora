<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\CardPatienceMoveRequest;
use App\Entity\PatienceGame;
use App\Entity\User;
use App\Enum\Game;
use App\Game\CardPatience\CardPatienceRules;
use App\Game\CardPatience\CardPatienceState;
use App\Service\CardPatienceView;
use App\Service\PatienceTable;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API d'une partie de FreeCell, de Pyramide ou de Golf solitaire (duel solo, 2 à 5 joueurs) :
 * /api/v1/jeux/{freecell|pyramide|golf-solitaire}/parties/{id}. Chaque réponse contient la nouvelle vue du joueur.
 */
#[Route('/api/v1/jeux/{jeu}/parties/{id}', requirements: ['jeu' => new EnumRequirement([Game::Freecell, Game::Pyramide, Game::GolfSolitaire]), 'id' => '\d+'], format: 'json')]
#[IsGranted('ROLE_USER', message: 'Connectez-vous pour jouer.', statusCode: Response::HTTP_UNAUTHORIZED)]
final class CardPatienceApiController extends AbstractPatienceApiController
{
    public function __construct(
        PatienceTable $patience,
        private readonly CardPatienceView $cardView,
    ) {
        parent::__construct($patience);
    }

    #[Route('', name: 'api_card_patience_game', methods: ['GET'])]
    public function show(#[MapEntity(id: 'id')] PatienceGame $game, string $jeu): JsonResponse
    {
        return $this->matches($game, $jeu) ?? $this->showGame($game);
    }

    /** Jouer une carte : {"card": "coeur-7", "to": "colonne-2"} (FreeCell), {"card": "coeur-6", "to": "pique-7"} (Pyramide), {"card": "coeur-7", "to": "pile"} (Golf). */
    #[Route('/deplacements', name: 'api_card_patience_move', methods: ['POST'])]
    public function move(#[MapEntity(id: 'id')] PatienceGame $game, string $jeu, Request $request, #[MapRequestPayload] CardPatienceMoveRequest $payload): JsonResponse
    {
        $auto = (bool) (json_decode($request->getContent(), true)['auto'] ?? true);

        return $this->matches($game, $jeu) ?? $this->play($game, $request, fn (User $user) => $this->patience->act(
            $user,
            $game,
            fn (CardPatienceState $state) => $this->rules($game)->move($state, $payload->card, $payload->to),
            $auto,
        ));
    }

    /** Retourner une carte du talon (Pyramide, Golf solitaire). */
    #[Route('/talon', name: 'api_card_patience_draw', methods: ['POST'])]
    public function draw(#[MapEntity(id: 'id')] PatienceGame $game, string $jeu, Request $request): JsonResponse
    {
        return $this->matches($game, $jeu) ?? $this->play($game, $request, fn (User $user) => $this->patience->act(
            $user,
            $game,
            fn (CardPatienceState $state) => $this->rules($game)->draw($state),
        ));
    }

    /** Bouton « Retour » : le coup précédent, contre 1 000 points. */
    #[Route('/retour', name: 'api_card_patience_undo', methods: ['POST'])]
    public function undo(#[MapEntity(id: 'id')] PatienceGame $game, string $jeu, Request $request): JsonResponse
    {
        return $this->matches($game, $jeu) ?? $this->play($game, $request, fn (User $user) => $this->patience->act(
            $user,
            $game,
            fn (CardPatienceState $state) => $this->rules($game)->undo($state),
        ));
    }

    /** Bouton « Arrêter » : la grille s'arrête pour ce joueur, avec son score du moment. */
    #[Route('/arret', name: 'api_card_patience_stop', methods: ['POST'])]
    public function stop(#[MapEntity(id: 'id')] PatienceGame $game, string $jeu, Request $request): JsonResponse
    {
        return $this->matches($game, $jeu) ?? $this->stopGame($game, $request);
    }

    /** Le temps d'une grille est écoulé : le serveur la ferme. */
    #[Route('/temps-ecoule', name: 'api_card_patience_timeout', methods: ['POST'])]
    public function timeout(#[MapEntity(id: 'id')] PatienceGame $game, string $jeu, Request $request): JsonResponse
    {
        return $this->matches($game, $jeu) ?? $this->timeoutGame($game, $request);
    }

    /** Le joueur quitte sa partie : sa grille est close au score du moment (sans bonus de temps). */
    #[Route('/abandon', name: 'api_card_patience_leave', methods: ['POST'])]
    public function leave(#[MapEntity(id: 'id')] PatienceGame $game, string $jeu, Request $request): Response
    {
        return $this->matches($game, $jeu) ?? $this->leaveGame($game, $request);
    }

    protected function accepts(Game $game): bool
    {
        return $game->isCardPatience();
    }

    protected function view(PatienceGame $game, int $me): array
    {
        return $this->cardView->forPlayer($game, $me);
    }

    /** Une partie d'un autre jeu que celui de l'adresse : introuvable ici. */
    private function matches(PatienceGame $game, string $jeu): ?JsonResponse
    {
        return $game->getRoom()->getGame()->value === $jeu ? null : throw $this->createNotFoundException();
    }

    private function rules(PatienceGame $game): CardPatienceRules
    {
        $rules = $this->patience->rules($game);
        \assert($rules instanceof CardPatienceRules);

        return $rules;
    }
}
