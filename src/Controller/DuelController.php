<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GameRoom;
use App\Entity\User;
use App\Service\DuelHistory;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Mes parties » : toutes les parties du joueur (duels solo et jeux à plusieurs), en deux onglets :
 * en cours et terminées, avec le classement et les points de chacun.
 * Et le résultat d'un duel, où l'on arrive à la fin de sa partie ou en la quittant.
 */
#[IsGranted('ROLE_USER')]
final class DuelController extends AbstractController
{
    #[Route('/mes-parties', name: 'app_my_duels', methods: ['GET'])]
    public function index(DuelHistory $history, #[MapQueryParameter] int $page = 1, #[MapQueryParameter] int $duel = 0): Response
    {
        return $this->list($history, false, $page, $duel);
    }

    #[Route('/mes-parties/terminees', name: 'app_my_duels_finished', methods: ['GET'])]
    public function finished(DuelHistory $history, #[MapQueryParameter] int $page = 1, #[MapQueryParameter] int $duel = 0): Response
    {
        return $this->list($history, true, $page, $duel);
    }

    /** Ancienne adresse de la page. */
    #[Route('/mes-duels', name: 'app_my_duels_old', methods: ['GET'])]
    public function old(): Response
    {
        return $this->redirectToRoute('app_my_duels', status: Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * Le résultat d'un duel : les joueurs qui ont fini, du gagnant au perdant ; ceux qui jouent encore (la page se met à jour).
     */
    #[Route('/mes-parties/{id}', name: 'app_duel_result', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function result(#[MapEntity(id: 'id')] GameRoom $room, DuelHistory $history): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $row = $history->one($room, $user) ?? throw $this->createNotFoundException();

        return $this->render('duel/result.html.twig', [
            'room' => $room,
            'duel' => DuelHistory::export($row),
        ]);
    }

    private function list(DuelHistory $history, bool $finished, int $page, int $duel): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $pages = $history->pageCount($user, $finished);

        if ($page < 1 || $page > $pages) {
            throw $this->createNotFoundException();
        }

        return $this->render('duel/index.html.twig', [
            'finished' => $finished,
            // Le classement exporté (rangs, gagnant) et le salon (dates, nombre de joueurs)
            'rows' => array_map(static fn (array $row): array => [...DuelHistory::export($row), 'room' => $row['room'], 'solo' => $row['game']->isPatience()], $history->page($user, $page, $finished)),
            'counts' => ['playing' => $history->count($user, false), 'finished' => $history->count($user, true)],
            'page' => $page,
            'pages' => $pages,
            'highlight' => $duel,
        ]);
    }
}
