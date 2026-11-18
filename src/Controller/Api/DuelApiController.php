<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GameRoom;
use App\Entity\User;
use App\Service\DuelHistory;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le résultat d'un duel solo (solitaire, Spider), pour la page du résultat et l'application mobile.
 * Seuls les joueurs du duel le voient ; les scores des autres n'apparaissent qu'une fois sa propre partie finie.
 */
#[Route('/api/v1/duels', format: 'json')]
final class DuelApiController extends AbstractController
{
    #[IsGranted('ROLE_USER', message: 'Connectez-vous pour voir ce duel.', statusCode: Response::HTTP_UNAUTHORIZED)]
    #[Route('/{id}', name: 'api_duel_result', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function result(#[MapEntity(id: 'id')] GameRoom $room, DuelHistory $history): JsonResponse
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $row = $history->one($room, $user) ?? throw $this->createNotFoundException();

        return $this->json(['duel' => DuelHistory::export($row)], headers: ['Cache-Control' => 'no-store']);
    }
}
