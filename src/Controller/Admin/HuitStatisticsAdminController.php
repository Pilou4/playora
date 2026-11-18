<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\HuitGame;
use App\Enum\Game;
use App\Service\Admin\HuitStatistics;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Statistiques du 8 américain : chiffres généraux, liste des parties et détail de chacune, manche par manche.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/jeux/huit-americain')]
final class HuitStatisticsAdminController extends AbstractController
{
    #[Route('/statistiques', name: 'admin_huit_statistics', methods: ['GET'])]
    public function index(
        HuitStatistics $statistics,
        #[MapQueryParameter] int $page = 1,
        #[MapQueryParameter] string $tri = HuitStatistics::DEFAULT_SORT,
        #[MapQueryParameter] string $ordre = 'desc',
    ): Response {
        $pages = $statistics->pageCount();

        if ($page < 1 || $page > $pages || !\array_key_exists($tri, HuitStatistics::SORTS) || !\in_array($ordre, ['asc', 'desc'], true)) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/huit/statistiques.html.twig', [
            'game' => Game::HuitAmericain,
            'summary' => $statistics->summary(),
            'rows' => $statistics->page($page, $tri, $ordre),
            'page' => $page,
            'sort' => $tri,
            'direction' => $ordre,
            'pages' => $pages,
        ]);
    }

    #[Route('/parties/{id}', name: 'admin_huit_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(#[MapEntity(id: 'id')] HuitGame $huit, HuitStatistics $statistics): Response
    {
        return $this->render('admin/huit/partie.html.twig', [
            'game' => Game::HuitAmericain,
            'huit' => $huit,
            ...$statistics->detail($huit),
        ]);
    }
}
