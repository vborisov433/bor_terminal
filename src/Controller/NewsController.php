<?php

namespace App\Controller;

use App\Entity\MarketSummary;
use App\Entity\NewsArticleInfo;
use App\Entity\PromptTemplate;
use App\Entity\Service;
use App\Repository\MarketSummaryRepository;
use App\Repository\NewsItemRepository;
use App\Repository\PromptTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
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
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class NewsController extends AbstractController
{
    #[Route('/', name: 'app_news')]
    public function index(Request $request, NewsItemRepository $repo, PaginatorInterface $paginator, EntityManagerInterface $em): Response
    {
        // 1. Setup Request
        $surpriseMin = $request->query->get('surprise_min');
        $impactMin = $request->query->get('impact_min');
        $page = max(1, (int)$request->query->get('page', 1));
        $limit = 12;

        // Check if we are actually filtering
        $hasFilters = is_numeric($surpriseMin) || is_numeric($impactMin);

        // 2. Service Check (Lightweight)
        $serviceRepo = $em->getRepository(Service::class);
        $service = $serviceRepo->findOneBy(['name' => 'bornews']);
        $isBornewsOnline = false;

        if ($service && $service->getLastSeen()) {
            $tenMinutesAgo = new \DateTime('-10 minutes');
            if ($service->getLastSeen() > $tenMinutesAgo) {
                $isBornewsOnline = true;
            }
        }

        // 3. MAIN DATA QUERY
        $qb = $repo->createQueryBuilder('n')
            ->leftJoin('n.articleInfo', 'a')->addSelect('a');

        // --- CRITICAL FIX START ---
        if ($hasFilters) {
            // CASE A: Filtering active.
            // We MUST sort by 'a.id' so MySQL can use the same index for filtering AND sorting.
            $qb->orderBy('n.id', 'DESC');

            if (is_numeric($surpriseMin)) {
                $qb->andWhere('a.newsSurpriseIndex >= :surprise_min')
                    ->setParameter('surprise_min', (int)$surpriseMin);
            }
            if (is_numeric($impactMin)) {
                $qb->andWhere('a.economyImpact >= :impact_min')
                    ->setParameter('impact_min', (int)$impactMin);
            }
        } else {
            // CASE B: No Filters (Default View).
            // We MUST sort by 'n.id'. This allows MySQL to instantly scan the Primary Key
            // of the main table and stop after 12 rows. No temporary table sorting needed.
            $qb->orderBy('n.id', 'DESC');
        }
        // --- CRITICAL FIX END ---

        $query = $qb->getQuery();
        $query->setFirstResult(($page - 1) * $limit);
        $query->setMaxResults($limit);

        $doctrinePaginator = new \Doctrine\ORM\Tools\Pagination\Paginator($query, false);

        // 4. OPTIMIZED COUNT QUERY
        // We reuse this logic to avoid running countAll() twice
        $countQb = $em->createQueryBuilder()
            ->select('count(a.id)')
            ->from(NewsArticleInfo::class, 'a');

        if ($hasFilters) {
            if (is_numeric($surpriseMin)) {
                $countQb->andWhere('a.newsSurpriseIndex >= :surprise_min')
                    ->setParameter('surprise_min', (int)$surpriseMin);
            }
            if (is_numeric($impactMin)) {
                $countQb->andWhere('a.economyImpact >= :impact_min')
                    ->setParameter('impact_min', (int)$impactMin);
            }
            $totalItems = $countQb->getQuery()->getSingleScalarResult();
            $totalNewsCount = $repo->countAll(); // Only run separate count if filtered
        } else {
            // If no filters, the "Total Pagination Items" IS the "Total News Count"
            // We run one fast query instead of two.
            $totalItems = $countQb->getQuery()->getSingleScalarResult();
            $totalNewsCount = $totalItems;
        }

        $totalPages = ceil($totalItems / $limit);

        // 5. First Date Query
        $firstNewsDate = $repo->createQueryBuilder('n')
            ->select('n.createdAt')
            ->orderBy('n.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleScalarResult();

        return $this->render('news/index.html.twig', [
            'pagination' => $doctrinePaginator,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'surprise_min' => $surpriseMin,
            'impact_min' => $impactMin,
            'total_news_count' => $totalNewsCount,
            'first_news_date' => $firstNewsDate,
            'isBornewsOnline' => $isBornewsOnline,
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

    #[Route('/news/all', name: 'app_news_all', methods: ['GET'])]
    public function getAllNews(Request $request, NewsItemRepository $repo): JsonResponse
    {
        $limit = (int)$request->query->get('limit', 20); // default to 20 if not provided

        $newsItems = $repo->createQueryBuilder('n')
            ->leftJoin('n.articleInfo', 'a')->addSelect('a')
            ->leftJoin('n.marketAnalyses', 'm')->addSelect('m')
            ->andWhere('a.newsSurpriseIndex > 4')
            ->orderBy('n.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

//        $data = array_map(function ($item) {
//            /** @var \App\Entity\NewsItem $item */
//            // return read from #link
//            return [
//                'id' => $item->getId(),
//                'title' => $item->getTitle(),
//                'news' => $item->getLink(),
//                'date' => $item->getDate()?->format('Y-m-d'),
//                'gptAnalysis' => $item->getGptAnalysis(),
//                'analyzed' => $item->isAnalyzed(),
//                'completed' => $item->isCompleted(),
//                'createdAt' => $item->getCreatedAt()?->format('Y-m-d H:i:s'),
//                'articleInfo' => $item->getArticleInfo() ? [
//                    'hasMarketImpact' => $item->getArticleInfo()->hasMarketImpact(),
//                    'titleHeadline' => $item->getArticleInfo()->getTitleHeadline(),
//                    'newsSurpriseIndex' => $item->getArticleInfo()->getNewsSurpriseIndex(),
//                    'economyImpact' => $item->getArticleInfo()->getEconomyImpact(),
//                    'macroKeywordHeatmap' => $item->getArticleInfo()->getMacroKeywordHeatmap(),
//                    'summary' => $item->getArticleInfo()->getSummary(),
//                ] : null,
//                'analyses' => implode(',',array_map(function ($ma) {
//                    return $ma->getMarket() .' '. $ma->getSentiment();
//                    return [
//                        'market' => $ma->getMarket(),
//                        'sentiment' => $ma->getSentiment(),
//                        'magnitude' => $ma->getMagnitude(),
//                        'reason' => $ma->getReason(),
//                        'keywords' => $ma->getKeywords(),
//                        'categories' => $ma->getCategories(),
//                    ];
//                }, $item->getMarketAnalyses()->toArray())),
//            ];
//        }, $newsItems);

        $data = array_map(fn($i) => implode('|', [
//            $i->getId(),
            $i->getTitle(),
            $i->getLink(),
//            $i->getDate()?->format('Y-m-d'),
//            $i->getGptAnalysis(),
//            $i->isAnalyzed(),
//            $i->isCompleted(),
//            $i->getCreatedAt()?->format('Y-m-d H:i:s'),
//            $i->getArticleInfo()?->toString(),
//            $info?$info->hasMarketImpact():'',
//            $info?$info->getTitleHeadline():'',
//            $info?$info->getNewsSurpriseIndex():'',
//            $info?$info->getEconomyImpact():'',
//            $info?$info->getMacroKeywordHeatmap():'',
//            $info?$info->getSummary():'',
            $i->getArticleInfo()?->getSummary(),
            $i->marketAnalysesToString()
//            implode(',', array_map(fn($ma) => $ma->getMarket().' '.$ma->getSentiment(), $i->getMarketAnalyses()->toArray()))

        ]), $newsItems);

        return new JsonResponse($data);
    }

    public function get_all_news(NewsItemRepository $repo)
    {
        $LIMIT_ARTICLES = 100;

        $newsItems = $repo->createQueryBuilder('n')
            ->leftJoin('n.articleInfo', 'a')->addSelect('a')
            ->leftJoin('n.marketAnalyses', 'm')->addSelect('m')
            ->andWhere('a.newsSurpriseIndex > 6')
            ->andWhere('a.economyImpact > 5')
            ->orderBy('n.id', 'DESC') // limit to 5 setMaxResults doesnt work
            ->getQuery()
            ->getResult();

        $newsItems = array_slice($newsItems, 0, $LIMIT_ARTICLES);

        /*
                $data = array_map(fn($i)=>implode('|',[
        //            $i->getId(),
                    $i->getTitle(),
                    $i->getLink(),
        //            $i->getDate()?->format('Y-m-d'),
        //            $i->getGptAnalysis(),
        //            $i->isAnalyzed(),
        //            $i->isCompleted(),
        //            $i->getCreatedAt()?->format('Y-m-d H:i:s'),
                    $i->getArticleInfo()?->toString(),
        //            $info?$info->hasMarketImpact():'',
        //            $info?$info->getTitleHeadline():'',
        //            $info?$info->getNewsSurpriseIndex():'',
        //            $info?$info->getEconomyImpact():'',
                    implode(',', array_map(fn($item) => is_scalar($item) ? $item : json_encode($item), $i->getArticleInfo()?->getMacroKeywordHeatmap() ?? [])),
                    $i->getArticleInfo()?->getSummary(),
                    $i->marketAnalysesToString()
        //            implode(',', array_map(fn($ma) => $ma->getMarket().' '.$ma->getSentiment(), $i->getMarketAnalyses()->toArray()))

                ]),$newsItems);
        */

        $data = array_map(function ($i) {
            return [
                'title' => $i->getTitle(),
                'link' => $i->getLink(),
                'article_info' => $i->getArticleInfo()?->toString(),
                'macro_heatmap' => $i->getArticleInfo()
                        ?->getMacroKeywordHeatmap() ?? [],
                'summary' => $i->getArticleInfo()?->getSummary(),
                'market_analysis' => $i->marketAnalysesToString()
            ];
        }, $newsItems);

//        dd($data[0]);
//        dd(json_encode($data[0]));


//        dd($data);

        return $data;
    }

    #[Route('/market-summary', name: 'api_news_market_summary', methods: ['GET', 'POST'])]
    public function marketSummary(
        Request                  $request,
        MarketSummaryRepository  $summaryRepo,
        PromptTemplateRepository $promptRepo,
        EntityManagerInterface   $em,

    ): Response
    {
        $id = $request->query->get('id');

        $promptTemplate = $promptRepo->findOneBy([], ['id' => 'DESC']);

        if (!$promptTemplate?->getId()) {
            $promptTemplate = new PromptTemplate();
            $promptTemplate->setTemplate('Default template text here...');
            $em->persist($promptTemplate);
            $em->flush();
        }

        if ($request->isMethod('POST')) {
            $newTemplate = $request->request->get('template');

            if ($newTemplate !== null && $newTemplate !== $promptTemplate->getTemplate()) {
                $newPromptTemplate = new PromptTemplate();
                $newPromptTemplate->setTemplate($newTemplate);
                $em->persist($newPromptTemplate);
                $em->flush();
                $promptTemplate = $newPromptTemplate; // update to latest
            }
        }

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
            'prompt_template' => $promptTemplate
        ]);
    }

    #[Route('/market-summary/delete/{id}', name: 'api_news_market_summary_delete', methods: ['POST'])]
    public function deleteMarketSummary(MarketSummaryRepository $repo, EntityManagerInterface $em, int $id): JsonResponse
    {
        $summary = $repo->find($id);
        if (!$summary) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        $em->remove($summary);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/api/market-summary', name: 'api_market_summary_json', methods: ['GET'])]
    public function marketSummaryJson(
        NewsItemRepository $repo,
        HttpClientInterface $http,
        EntityManagerInterface $em,
        PromptTemplateRepository $promptRepo
    ): JsonResponse {
        $start = microtime(true);

        // 1. Prepare Data & Template
        $newsData = json_encode($this->get_all_news($repo), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $templateEntity = $promptRepo->findOneBy([], ['id' => 'DESC']);
        // Flatten template to single line to avoid formatting issues
        $templateParams = $templateEntity ? trim(preg_replace('/\s+/', ' ', $templateEntity->getTemplate())) : '';

        // 2. Build the "Sandwich" Prompt
        $question = <<<TEXT
*** SYSTEM INSTRUCTION: CRITICAL OVERRIDE ***
1. YOU ARE A CODE-ONLY TERMINAL.
2. DISABLE ALL IMAGE GENERATION TOOLS. DO NOT USE DALL-E, PLOTTING, OR CHARTING TOOLS.
3. IF ASKED FOR CHARTS, RENDER THEM AS HTML TABLES OR BOOTSTRAP PROGRESS BARS.
4. OUTPUT RAW STRING ONLY.

*** INPUT DATA ***
{$newsData}

*** TASK INSTRUCTIONS ***
Analyze the data above for markets: DOW, AUDJPY, AUDUSD, DXY, FED INTEREST RATE.
1. Calculate % of bullish vs bearish stories per market.
2. Compare current sentiment to historical patterns.
3. Probability bullets for up/down moves.
4. General news summary.

*** FORMATTING INSTRUCTIONS (STRICT HTML ONLY) ***
Return a single HTML string using Bootstrap 5 classes (container-fluid p-1, card mb-2).
- All text color must be black (text-dark).
- Use Bootstrap Icons (bi-arrow-up, bi-arrow-down) for direction.
- DO NOT generate an image file.
- DO NOT generate a chart.
- Structure:
{$templateParams}

*** FINAL VERIFICATION ***
Check your output. Does it contain an image URL? If yes, delete it.
Ensure the output starts immediately with "```html" and contains ONLY valid HTML code.
TEXT;

        // 3. Send Request with Retry Logic
        $maxRetries = 2;
        $html = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $http->request('POST', 'http://localhost:5000/api/ask-gpt', [
                    'json' => ['question' => $question]
                ]);

                if ($response->getStatusCode() !== 200) {
                    throw new \RuntimeException('API Status: ' . $response->getStatusCode());
                }

                $apiResponse = $response->toArray();

                // Clean Markdown tags from response
                $html = str_replace(['```html', '```'], '', $apiResponse['answer'] ?? '');

                // Success: Break loop
                break;

            } catch (\Throwable $e) {
                if ($attempt === $maxRetries) {
                    return new JsonResponse(['error' => $e->getMessage()], 500);
                }
                sleep(7); // Wait before retry
            }
        }

        // 4. Persist & Return
        if ($html) {
            $summary = new MarketSummary();
            $summary->setHtmlResult($html);
            $summary->setCreatedAt(new \DateTimeImmutable());
            $summary->setTimeLoaded((int)round(microtime(true) - $start));
            $em->persist($summary);
            $em->flush();
        }

        return new JsonResponse(['html_result' => $html]);
    }
}
