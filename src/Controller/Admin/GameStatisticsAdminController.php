<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BeloteGame;
use App\Enum\Game;
use App\Service\Admin\BeloteStatistics;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Statistiques d'un jeu de la famille belote : chiffres généraux, liste des parties et détail de chacune.
 * Le tarot a son propre contrôleur (TarotStatisticsAdminController).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/jeux/{game}', requirements: ['game' => new EnumRequirement([Game::Belote, Game::Coinche, Game::Contree])])]
final class GameStatisticsAdminController extends AbstractController
{
    #[Route('/statistiques', name: 'admin_game_statistics', methods: ['GET'])]
    public function index(
        Game $game,
        BeloteStatistics $statistics,
        #[MapQueryParameter] int $page = 1,
        #[MapQueryParameter] string $tri = BeloteStatistics::DEFAULT_SORT,
        #[MapQueryParameter] string $ordre = 'desc',
    ): Response {
        $pages = $statistics->pageCount($game);

        if ($page < 1 || $page > $pages || !\array_key_exists($tri, BeloteStatistics::SORTS) || !\in_array($ordre, ['asc', 'desc'], true)) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/jeu/statistiques.html.twig', [
            'game' => $game,
            'summary' => $statistics->summary($game),
            'rows' => $statistics->page($game, $page, $tri, $ordre),
            'page' => $page,
            'sort' => $tri,
            'direction' => $ordre,
            'pages' => $pages,
        ]);
    }

    #[Route('/parties/{id}', name: 'admin_game_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(Game $game, #[MapEntity(id: 'id')] BeloteGame $belote, BeloteStatistics $statistics): Response
    {
        if ($belote->getRoom()->getGame() !== $game) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/jeu/partie.html.twig', [
            'game' => $game,
            'belote' => $belote,
            ...$statistics->detail($belote),
        ]);
    }
}
