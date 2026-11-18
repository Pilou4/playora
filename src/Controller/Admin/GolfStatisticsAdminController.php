<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\GolfGame;
use App\Enum\Game;
use App\Service\Admin\GolfStatistics;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Statistiques du Golf : chiffres généraux, liste des parties et détail de chacune, trou par trou.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/jeux/golf')]
final class GolfStatisticsAdminController extends AbstractController
{
    #[Route('/statistiques', name: 'admin_golf_statistics', methods: ['GET'])]
    public function index(
        GolfStatistics $statistics,
        #[MapQueryParameter] int $page = 1,
        #[MapQueryParameter] string $tri = GolfStatistics::DEFAULT_SORT,
        #[MapQueryParameter] string $ordre = 'desc',
    ): Response {
        $pages = $statistics->pageCount();

        if ($page < 1 || $page > $pages || !\array_key_exists($tri, GolfStatistics::SORTS) || !\in_array($ordre, ['asc', 'desc'], true)) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/golf/statistiques.html.twig', [
            'game' => Game::Golf,
            'summary' => $statistics->summary(),
            'rows' => $statistics->page($page, $tri, $ordre),
            'page' => $page,
            'sort' => $tri,
            'direction' => $ordre,
            'pages' => $pages,
        ]);
    }

    #[Route('/parties/{id}', name: 'admin_golf_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(#[MapEntity(id: 'id')] GolfGame $golf, GolfStatistics $statistics): Response
    {
        return $this->render('admin/golf/partie.html.twig', [
            'game' => Game::Golf,
            'golf' => $golf,
            ...$statistics->detail($golf),
        ]);
    }
}
