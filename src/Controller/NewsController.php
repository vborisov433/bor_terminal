<?php

namespace App\Controller;

use App\Entity\MarketSummary;
use App\Repository\MarketSummaryRepository;
use App\Repository\NewsItemRepository;
use Doctrine\ORM\EntityManagerInterface;
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

final class NewsController extends AbstractController
{
    #[Route('/', name: 'app_news')]
    public function index(Request $request, NewsItemRepository $repo, PaginatorInterface $paginator): Response
    {
        $surpriseMin = $request->query->get('surprise_min');
        $impactMin = $request->query->get('impact_min');
        $page = max(1, (int)$request->query->get('page', 1)); // current page number

        $qb = $repo->createQueryBuilder('n')
            ->leftJoin('n.articleInfo', 'a')->addSelect('a')
            ->leftJoin('n.marketAnalyses', 'm')->addSelect('m')
            ->orderBy('n.id', 'DESC');

        if (is_numeric($surpriseMin)) {
            $qb->andWhere('a.newsSurpriseIndex >= :surprise_min')
                ->setParameter('surprise_min', (int)$surpriseMin);
        }
        if (is_numeric($impactMin)) {
            $qb->andWhere('a.economyImpact >= :impact_min')
                ->setParameter('impact_min', (int)$impactMin);
        }

        $pagination = $paginator->paginate(
            $qb, // query NOT ->getQuery()
            $page,
            12 // items per page
        );

        return $this->render('news/index.html.twig', [
            'pagination' => $pagination,
            'surprise_min' => $surpriseMin,
            'impact_min' => $impactMin,
        ]);
    }


    #[Route('/news/refresh', name: 'app_news_refresh', methods: ['POST'])]
    public function refresh(KernelInterface $kernel, Request $request): JsonResponse
    {
        $app = new Application($kernel);
        $app->setAutoExit(false);
        $input = new ArrayInput(['command' => 'app:analyze-gpt']);
        $output = new BufferedOutput();
        $exitCode = $app->run($input, $output);

        return new JsonResponse([
            'success' => $exitCode === 0,
            'output' => $output->fetch(),
        ]);
    }

    public function get_all_news(NewsItemRepository $repo)
    {
        // Fetch all NewsItem entities and join related data
        $newsItems = $repo->createQueryBuilder('n')
            ->leftJoin('n.articleInfo', 'a')->addSelect('a')
            ->leftJoin('n.marketAnalyses', 'm')->addSelect('m')
            ->andWhere('a.newsSurpriseIndex > 4')
            ->orderBy('n.id', 'DESC')
            ->getQuery()
            ->getResult();

        $data = array_map(function ($item) {
            /** @var \App\Entity\NewsItem $item */
            return [
                'id' => $item->getId(),
                'title' => $item->getTitle(),
                'link' => $item->getLink(),
                'date' => $item->getDate()?->format('Y-m-d'),
                'gptAnalysis' => $item->getGptAnalysis(),
                'analyzed' => $item->isAnalyzed(),
                'completed' => $item->isCompleted(),
                'createdAt' => $item->getCreatedAt()?->format('Y-m-d H:i:s'),
                'articleInfo' => $item->getArticleInfo() ? [
                    'hasMarketImpact' => $item->getArticleInfo()->hasMarketImpact(),
                    'titleHeadline' => $item->getArticleInfo()->getTitleHeadline(),
                    'newsSurpriseIndex' => $item->getArticleInfo()->getNewsSurpriseIndex(),
                    'economyImpact' => $item->getArticleInfo()->getEconomyImpact(),
                    'macroKeywordHeatmap' => $item->getArticleInfo()->getMacroKeywordHeatmap(),
                    'summary' => $item->getArticleInfo()->getSummary(),
                ] : null,
                'marketAnalyses' => array_map(function ($ma) {
                    return [
                        'market' => $ma->getMarket(),
                        'sentiment' => $ma->getSentiment(),
                        'magnitude' => $ma->getMagnitude(),
                        'reason' => $ma->getReason(),
                        'keywords' => $ma->getKeywords(),
                        'categories' => $ma->getCategories(),
                    ];
                }, $item->getMarketAnalyses()->toArray()),
            ];
        }, $newsItems);

        return $data;
    }
    #[Route('/market-summary', name: 'api_news_market_summary', methods: ['GET'])]
    public function marketSummary(
        Request $request,
        MarketSummaryRepository $summaryRepo
    ): Response {
        $id = $request->query->get('id');

        $summaries = $summaryRepo->findBy([], ['createdAt' => 'DESC']);

        $selectedSummary = null;
        if ($id) {
            $selectedSummary = $summaryRepo->find($id);
        }

        if (!$selectedSummary) {
            $selectedSummary = $summaryRepo->findOneBy([], ['createdAt' => 'DESC']);
        }

        return $this->render('market-summary/index.html.twig', [
            'summaries' => $summaries,
            'market_summary_html' => $selectedSummary?->getHtmlResult(),
            'selected_summary_id' => $selectedSummary?->getId(),
            'selected_summary_date' => $selectedSummary?->getCreatedAt(),
            'time_loaded' => $selectedSummary?->getTimeLoaded(),
        ]);
    }


    #[Route('/api/market-summary', name: 'api_market_summary_json', methods: ['GET'])]
    public function marketSummaryJson(
        NewsItemRepository $repo,
        HttpClientInterface $http,
        EntityManagerInterface $em
    ): JsonResponse {
        $start = microtime(true);
        $question = [
            'question' => json_encode($this->get_all_news($repo)) .
                'style for mobile container-fluid p-1 ,use bootstrap 5, icons, jquery imported, return html string only,Characters less than 4200
                 use table-responsive, with headers Market & Direction,Quick Summary
                 given the information show summary
                 use table-responsive add future positive and negative events
                explain what to look for in the markets with bullets
                ',
        ];

        $maxRetries = 5;
        $retryDelaySeconds = 7;
        $html = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $http->request('POST', 'http://localhost:5000/api/ask-gpt', [
                    'json' => $question
                ]);

                if ($response->getStatusCode() >= 300) {
                    throw new \Exception('API returned a non-successful status code: ' . $response->getStatusCode());
                }

                $apiResponse = $response->toArray();
                $html = $apiResponse['answer'] ?? null;
                $html = str_replace('```html', '', $html);
                $html = str_replace('```', '', $html);

                $summary = new MarketSummary();
                $summary->setHtmlResult($html);
                $summary->setCreatedAt(new \DateTimeImmutable());
                $timeLoaded = (int) round(microtime(true) - $start);
                $summary->setTimeLoaded($timeLoaded);
                $em->persist($summary);
                $em->flush();

                break;
            } catch (\Throwable $e) {
                if ($attempt === $maxRetries) {
                    return new JsonResponse([
                        'html_result' => 'Error after max retries: ' . $e->getMessage(),
                    ], 500);
                }
                sleep($retryDelaySeconds);
            }
        }

        return new JsonResponse([
            'html_result' => $html ?? 'No summary could be generated after ' . $maxRetries . ' attempts.'
        ]);
    }
}
