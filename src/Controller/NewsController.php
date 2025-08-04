<?php

namespace App\Controller;

use App\Repository\NewsItemRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NewsController extends AbstractController
{
    #[Route('/', name: 'app_news')]
    public function index(NewsItemRepository $repo): Response
    {
        $newsItems = $repo->createQueryBuilder('n')
            ->leftJoin('n.articleInfo', 'a')->addSelect('a')
            ->leftJoin('n.marketAnalyses', 'm')->addSelect('m')
            ->orderBy('n.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('news/index.html.twig', [
            'newsItems' => $newsItems
        ]);
    }
}
