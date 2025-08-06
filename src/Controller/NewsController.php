<?php

namespace App\Controller;

use App\Repository\NewsItemRepository;
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

    public function get_all_news(NewsItemRepository $repo): JsonResponse
    {
        // Fetch all NewsItem entities and join related data
        $newsItems = $repo->createQueryBuilder('n')
            ->leftJoin('n.articleInfo', 'a')->addSelect('a')
            ->leftJoin('n.marketAnalyses', 'm')->addSelect('m')
            ->orderBy('n.id', 'DESC')
            ->getQuery()
            ->getResult();

        $data = array_map(function($item) {
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
                'marketAnalyses' => array_map(function($ma) {
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

        return new JsonResponse($data);
    }

    #[Route('/market-summary', name: 'api_news_market_summary', methods: ['GET'])]
    public function marketSummary(NewsItemRepository $repo, HttpClientInterface $http)
    {
        $question = [
            'question' => $this->get_all_news($repo). ' given the information show summary for different markets in short way, add bullets top news points that move markets, style in bootstrap 5 table and html, return in json format `html_result`' ,
        ];

        try {
            $response = $http->request(
                'POST',
                'http://localhost:5000/api/ask-gpt',
                [ 'json' => $question ]
            );

            $data = $response->toArray(); //make data is array to string
            $summaryJson = $data['answer'] ?? '';

            if (preg_match('/```json(.*?)```/s', $summaryJson, $matches)) {
                $jsonRaw = trim($matches[1]);
                $jsonRaw = trim($jsonRaw, "\"\"\r\n");
                $jsonRaw = preg_replace('/`([^`]*)`/s', '"$1"', $jsonRaw);
                $data = json_decode($jsonRaw, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    dd('JSON Error: ' . json_last_error_msg());
                }
            }

            return $this->render('/market-summary/index.html.twig', [
                'market_summary_html' => $data['html_result'] ?? null,
            ]);

        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'Error talking to GPT service',
                'details' => $e->getMessage(), // maybe hide in prod
            ], 500);
        }
    }
}
