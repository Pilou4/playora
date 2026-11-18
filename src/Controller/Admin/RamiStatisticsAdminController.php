<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\RamiGame;
use App\Enum\Game;
use App\Service\Admin\RamiStatistics;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Statistiques du rami : chiffres généraux, liste des parties et détail de chacune, manche par manche.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/jeux/rami')]
final class RamiStatisticsAdminController extends AbstractController
{
    #[Route('/statistiques', name: 'admin_rami_statistics', methods: ['GET'])]
    public function index(
        RamiStatistics $statistics,
        #[MapQueryParameter] int $page = 1,
        #[MapQueryParameter] string $tri = RamiStatistics::DEFAULT_SORT,
        #[MapQueryParameter] string $ordre = 'desc',
    ): Response {
        $pages = $statistics->pageCount();

        if ($page < 1 || $page > $pages || !\array_key_exists($tri, RamiStatistics::SORTS) || !\in_array($ordre, ['asc', 'desc'], true)) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/rami/statistiques.html.twig', [
            'game' => Game::Rami,
            'summary' => $statistics->summary(),
            'rows' => $statistics->page($page, $tri, $ordre),
            'page' => $page,
            'sort' => $tri,
            'direction' => $ordre,
            'pages' => $pages,
        ]);
    }

    #[Route('/parties/{id}', name: 'admin_rami_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(#[MapEntity(id: 'id')] RamiGame $rami, RamiStatistics $statistics): Response
    {
        return $this->render('admin/rami/partie.html.twig', [
            'game' => Game::Rami,
            'rami' => $rami,
            ...$statistics->detail($rami),
        ]);
    }
}
