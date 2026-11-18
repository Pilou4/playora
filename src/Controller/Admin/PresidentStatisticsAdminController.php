<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\PresidentGame;
use App\Enum\Game;
use App\Service\Admin\PresidentStatistics;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Statistiques du Président : chiffres généraux, liste des parties et détail de chacune, manche par manche.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/jeux/president')]
final class PresidentStatisticsAdminController extends AbstractController
{
    #[Route('/statistiques', name: 'admin_president_statistics', methods: ['GET'])]
    public function index(
        PresidentStatistics $statistics,
        #[MapQueryParameter] int $page = 1,
        #[MapQueryParameter] string $tri = PresidentStatistics::DEFAULT_SORT,
        #[MapQueryParameter] string $ordre = 'desc',
    ): Response {
        $pages = $statistics->pageCount();

        if ($page < 1 || $page > $pages || !\array_key_exists($tri, PresidentStatistics::SORTS) || !\in_array($ordre, ['asc', 'desc'], true)) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/president/statistiques.html.twig', [
            'game' => Game::President,
            'summary' => $statistics->summary(),
            'rows' => $statistics->page($page, $tri, $ordre),
            'page' => $page,
            'sort' => $tri,
            'direction' => $ordre,
            'pages' => $pages,
        ]);
    }

    #[Route('/parties/{id}', name: 'admin_president_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(#[MapEntity(id: 'id')] PresidentGame $president, PresidentStatistics $statistics): Response
    {
        return $this->render('admin/president/partie.html.twig', [
            'game' => Game::President,
            'president' => $president,
            ...$statistics->detail($president),
        ]);
    }
}
