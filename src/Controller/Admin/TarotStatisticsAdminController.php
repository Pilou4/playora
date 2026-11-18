<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\TarotGame;
use App\Enum\Game;
use App\Service\Admin\TarotStatistics;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Statistiques du tarot : chiffres généraux, liste des parties et détail de chacune.
 * Mêmes adresses que pour la belote (/admin/jeux/tarot/…), mais un preneur contre la défense au lieu de deux équipes.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/jeux/tarot')]
final class TarotStatisticsAdminController extends AbstractController
{
    #[Route('/statistiques', name: 'admin_tarot_statistics', methods: ['GET'])]
    public function index(
        TarotStatistics $statistics,
        #[MapQueryParameter] int $page = 1,
        #[MapQueryParameter] string $tri = TarotStatistics::DEFAULT_SORT,
        #[MapQueryParameter] string $ordre = 'desc',
    ): Response {
        $pages = $statistics->pageCount();

        if ($page < 1 || $page > $pages || !\array_key_exists($tri, TarotStatistics::SORTS) || !\in_array($ordre, ['asc', 'desc'], true)) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/tarot/statistiques.html.twig', [
            'game' => Game::Tarot,
            'summary' => $statistics->summary(),
            'rows' => $statistics->page($page, $tri, $ordre),
            'page' => $page,
            'sort' => $tri,
            'direction' => $ordre,
            'pages' => $pages,
        ]);
    }

    #[Route('/parties/{id}', name: 'admin_tarot_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(#[MapEntity(id: 'id')] TarotGame $tarot, TarotStatistics $statistics): Response
    {
        return $this->render('admin/tarot/partie.html.twig', [
            'game' => Game::Tarot,
            'tarot' => $tarot,
            ...$statistics->detail($tarot),
        ]);
    }
}
