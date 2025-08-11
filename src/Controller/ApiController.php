<?php

namespace App\Controller;

use App\Entity\MarketSummary;
use App\Entity\NewsItem;
use App\Entity\PromptTemplate;
use App\Repository\MarketSummaryRepository;
use App\Repository\NewsItemRepository;
use App\Repository\PromptTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use DOMDocument;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ApiController extends AbstractController
{
    #[Route('/api/news/check', name: 'api_news_check', methods: ['POST'])]
    public function checkNewsItem(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['link']) || !is_string($data['link'])) {
            return $this->json(['error' => 'Missing or invalid "link"'], 400);
        }

        $link = $data['link'];

        $exists = $em->getRepository(NewsItem::class)->findOneBy(['link' => $link]) !== null;

        return $this->json(['exists' => $exists]);
    }
}
