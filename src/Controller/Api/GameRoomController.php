<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\CreateGameRoomRequest;
use App\Entity\GameRoom;
use App\Entity\User;
use App\Enum\Game;
use App\Repository\GameRoomRepository;
use App\Service\GameRoomManager;
use App\Service\GameRoomState;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API des salons, utilisée par la page /jeux/{game} (et plus tard par l'application mobile).
 * Chaque action renvoie le nouvel état complet de la liste ; les erreurs suivent le format
 * « problem+json » (RFC 9457), comme les erreurs de validation de Symfony.
 */
#[Route('/api/v1/jeux/{game}', requirements: ['game' => new EnumRequirement(Game::class)], format: 'json')]
final class GameRoomController extends AbstractController
{
    private const string LOGIN_REQUIRED = 'Connectez-vous pour jouer.';

    public function __construct(
        private readonly GameRoomManager $manager,
        private readonly GameRoomState $state,
        private readonly GameRoomRepository $rooms,
        private readonly RateLimiterFactoryInterface $gameActionsLimiter,
    ) {
    }

    #[Route('/salons', name: 'api_game_rooms', methods: ['GET'])]
    public function list(Game $game): JsonResponse
    {
        $this->manager->releaseAbsentPlayers($game);

        return $this->stateResponse($game);
    }

    #[Route('/salons', name: 'api_game_room_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER', message: self::LOGIN_REQUIRED, statusCode: Response::HTTP_UNAUTHORIZED)]
    public function create(Game $game, Request $request, #[MapRequestPayload] CreateGameRoomRequest $payload): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn () => $this->manager->create($this->player(), $game, $payload->targetPoints, $payload->announcements), Response::HTTP_CREATED);
    }

    #[Route('/salons/{id}/places/{position}', name: 'api_game_room_join', requirements: ['id' => '\d+', 'position' => '[1-9]'], methods: ['POST'])]
    #[IsGranted('ROLE_USER', message: self::LOGIN_REQUIRED, statusCode: Response::HTTP_UNAUTHORIZED)]
    public function join(Game $game, int $id, int $position, Request $request): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn () => $this->manager->join($this->player(), $this->room($game, $id), $position));
    }

    /**
     * Mode test (PLAYORA_BOTS=1) : un robot prend la place libre.
     */
    #[Route('/salons/{id}/places/{position}/robot', name: 'api_game_room_bot', requirements: ['id' => '\d+', 'position' => '[1-9]'], methods: ['POST'])]
    #[IsGranted('ROLE_USER', message: self::LOGIN_REQUIRED, statusCode: Response::HTTP_UNAUTHORIZED)]
    public function addBot(Game $game, int $id, int $position, Request $request): JsonResponse
    {
        if (!$this->manager->botsEnabled()) {
            throw $this->createNotFoundException();
        }

        return $this->guard($request) ?? $this->run($game, fn () => $this->manager->addBot($this->player(), $this->room($game, $id), $position));
    }

    #[Route('/salons/{id}/joueurs', name: 'api_game_room_leave', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[IsGranted('ROLE_USER', message: self::LOGIN_REQUIRED, statusCode: Response::HTTP_UNAUTHORIZED)]
    public function leave(Game $game, int $id, Request $request): JsonResponse
    {
        return $this->guard($request) ?? $this->run($game, fn () => $this->manager->leave($this->player(), $this->room($game, $id)));
    }

    /**
     * Signal de présence : sans lui pendant 90 secondes, la place est libérée.
     */
    #[Route('/presence', name: 'api_game_presence', methods: ['POST'])]
    #[IsGranted('ROLE_USER', message: self::LOGIN_REQUIRED, statusCode: Response::HTTP_UNAUTHORIZED)]
    public function presence(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'Requête refusée.');
        }

        $this->manager->markPresent($this->player());

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Protection contre les requêtes venant d'un autre site (CSRF) et les clics en rafale.
     */
    private function guard(Request $request): ?JsonResponse
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'Requête refusée.');
        }

        if (!$this->gameActionsLimiter->create((string) $this->player()->getId())->consume()->isAccepted()) {
            return $this->problem(Response::HTTP_TOO_MANY_REQUESTS, 'Trop d’actions d’affilée : patientez quelques secondes.');
        }

        return null;
    }

    private function run(Game $game, \Closure $action, int $status = Response::HTTP_OK): JsonResponse
    {
        try {
            $action();
        } catch (\DomainException $exception) {
            return $this->problem(Response::HTTP_CONFLICT, $exception->getMessage(), $game);
        }

        return $this->stateResponse($game, $status);
    }

    private function stateResponse(Game $game, int $status = Response::HTTP_OK): JsonResponse
    {
        $user = $this->getUser();
        $response = new JsonResponse($this->state->forGame($game, $user instanceof User ? $user : null), $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Erreur au format problem+json ; avec l'état à jour quand c'est utile (place prise entre-temps…).
     */
    private function problem(int $status, string $detail, ?Game $game = null): JsonResponse
    {
        $body = ['status' => $status, 'title' => Response::$statusTexts[$status] ?? 'Error', 'detail' => $detail];

        if (null !== $game) {
            $user = $this->getUser();
            $body['state'] = $this->state->forGame($game, $user instanceof User ? $user : null);
        }

        return new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
    }

    private function room(Game $game, int $id): GameRoom
    {
        $room = $this->rooms->findWithSeats($id);

        if (null === $room || $room->getGame() !== $game) {
            throw new \DomainException('Ce salon n’existe plus.');
        }

        return $room;
    }

    private function player(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException(self::LOGIN_REQUIRED);
        }

        return $user;
    }
}
