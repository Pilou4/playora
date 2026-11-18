<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\PatienceGame;
use App\Enum\Game;
use App\Service\Admin\PatienceStatistics;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Statistiques d'un jeu de patience (solitaire, Spider) : chiffres généraux, liste des parties et détail de chacune,
 * manche par manche. Mêmes adresses que pour les autres jeux (/admin/jeux/solitaire/…).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/jeux/{game}', requirements: ['game' => new EnumRequirement([Game::Solitaire, Game::SolitaireHarmonie, Game::SpiderBeginner, Game::Spider, Game::SpiderPro, Game::Freecell, Game::Pyramide, Game::GolfSolitaire])])]
final class PatienceStatisticsAdminController extends AbstractController
{
    #[Route('/statistiques', name: 'admin_patience_statistics', methods: ['GET'])]
    public function index(
        Game $game,
        PatienceStatistics $statistics,
        #[MapQueryParameter] int $page = 1,
        #[MapQueryParameter] string $tri = PatienceStatistics::DEFAULT_SORT,
        #[MapQueryParameter] string $ordre = 'desc',
    ): Response {
        $pages = $statistics->pageCount($game);

        if ($page < 1 || $page > $pages || !\array_key_exists($tri, PatienceStatistics::SORTS) || !\in_array($ordre, ['asc', 'desc'], true)) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/patience/statistiques.html.twig', [
            'game' => $game,
            'summary' => $statistics->summary($game),
            'rows' => $statistics->page($game, $page, $tri, $ordre),
            'page' => $page,
            'sort' => $tri,
            'direction' => $ordre,
            'pages' => $pages,
        ]);
    }

    #[Route('/parties/{id}', name: 'admin_patience_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(Game $game, #[MapEntity(id: 'id')] PatienceGame $patience, PatienceStatistics $statistics): Response
    {
        if ($patience->getRoom()->getGame() !== $game) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/patience/partie.html.twig', [
            'game' => $game,
            'patience' => $patience,
            ...$statistics->detail($patience),
        ]);
    }
}
