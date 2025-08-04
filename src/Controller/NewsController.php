<?php

namespace App\Controller;

use App\Repository\NewsItemRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NewsController extends AbstractController
{
    #[Route('/', name: 'app_news')]
    public function index(Request $request, NewsItemRepository $repo): Response
    {
        $surpriseMin = $request->query->get('surprise_min');
        $impactMin = $request->query->get('impact_min');

        $qb = $repo->createQueryBuilder('n')
            ->leftJoin('n.articleInfo', 'a')->addSelect('a')
            ->leftJoin('n.marketAnalyses', 'm')->addSelect('m')
            ->orderBy('n.id', 'DESC');

        if (is_numeric($surpriseMin)) {
            $qb->andWhere('a.newsSurpriseIndex > :surprise_min')
                ->setParameter('surprise_min', (int)$surpriseMin);
        }
        if (is_numeric($impactMin)) {
            $qb->andWhere('a.economyImpact > :impact_min')
                ->setParameter('impact_min', (int)$impactMin);
        }

        $newsItems = $qb->getQuery()->getResult();

        return $this->render('news/index.html.twig', [
            'newsItems' => $newsItems,
            'surprise_min' => $surpriseMin,
            'impact_min' => $impactMin,
        ]);
    }

//    #[Route('/', name: 'app_news')]
//    public function index(NewsItemRepository $repo): Response
//    {
//        $newsItems = $repo->createQueryBuilder('n')
//            ->leftJoin('n.articleInfo', 'a')->addSelect('a')
//            ->leftJoin('n.marketAnalyses', 'm')->addSelect('m')
//            ->orderBy('n.id', 'DESC')
//            ->getQuery()
//            ->getResult();
//
//        return $this->render('news/index.html.twig', [
//            'newsItems' => $newsItems
//        ]);
//    }
}
