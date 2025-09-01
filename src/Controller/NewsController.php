<?php

namespace App\Controller;

use App\Entity\MarketSummary;
use App\Entity\PromptTemplate;
use App\Entity\Service;
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

final class NewsController extends AbstractController
{
    #[Route('/', name: 'app_news')]
    public function index(Request $request, NewsItemRepository $repo, PaginatorInterface $paginator, EntityManagerInterface $em): Response
    {
        $surpriseMin = $request->query->get('surprise_min');
        $impactMin = $request->query->get('impact_min');
        $page = max(1, (int)$request->query->get('page', 1)); // current page number

        $serviceRepo = $em->getRepository(Service::class);
        $service = $serviceRepo->findOneBy(['name' => 'bornews']);

        $isBornewsOnline = false;
        if ($service && $service->getLastSeen()) {
            $tenMinutesAgo = new \DateTime('-10 minutes');
            if ($service->getLastSeen() > $tenMinutesAgo) {
                $isBornewsOnline = true;
            }
        }

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
            $qb,
            $page,
            12 // items per page
        );

        $firstNewsDate = $repo->createQueryBuilder('n')
            ->select('n.createdAt')
            ->orderBy('n.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleScalarResult();

        return $this->render('news/index.html.twig', [
            'pagination' => $pagination,
            'surprise_min' => $surpriseMin,
            'impact_min' => $impactMin,
            'total_news_count' => $repo->countAll(),
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
        $limit = (int) $request->query->get('limit', 20); // default to 20 if not provided

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

        $data = array_map(fn($i)=>implode('|',[
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

        ]),$newsItems);

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

        $data = array_map(function($i) {
            return [
                'title'        => $i->getTitle(),
                'link'         => $i->getLink(),
                'article_info' => $i->getArticleInfo()?->toString(),
                'macro_heatmap'=> $i->getArticleInfo()
                        ?->getMacroKeywordHeatmap() ?? [],
                'summary'      => $i->getArticleInfo()?->getSummary(),
                'market_analysis' => $i->marketAnalysesToString()
            ];
        }, $newsItems);

//        dd($data[0]);
//        dd(json_encode($data[0]));


//        dd($data);

        return $data;
    }

    #[Route('/market-summary', name: 'api_news_market_summary', methods: ['GET','POST'])]
    public function marketSummary(
        Request $request,
        MarketSummaryRepository $summaryRepo,
        PromptTemplateRepository $promptRepo,
        EntityManagerInterface $em,

    ): Response {
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

        $promptTemplate = $promptRepo->findOneBy([], ['id' => 'DESC']);

        $_question = json_encode($this->get_all_news($repo)) .' '. $promptTemplate->getTemplate();
//            '
//            read all news here, analyze them,
//            markets: dow audjpy audusd dxy fed interest rate
//            1. use card list overall short summary with bullets,
//            2. table format with list for each market with columns: "market&sentiment icon direction" "magnitude rate 0-10"  "reason",
//            3. use card for each market from above what can be bullish or bearish in future
//            4. create card with what news or events to watch in the markets,
//            5. div label for total characters for this response,
//            return html string only in one line no whitespace use "bootstrap 5" icons,colors "container-fluid p-1" "card mb-2"
//                ';
//        with columns: market&sentiment, magnitude 0-10, reason and td colspan=3 for future Positive,Negative Events
//        in card list overal short summary
//            in card What To Watch in the markets
        //response Total characters < 5000,

        $_question =preg_replace('/\s+/', ' ', trim($_question));

//        dd(strlen($_question));
//        dd($_question);

        $question = [
            'question' =>  $_question
        ];

        $maxRetries = 2;
        $retryDelaySeconds = 7;
        $html = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $http->request('POST', 'http://localhost:5000/api/ask-gpt', [
                    'json' => $question
                ]);

                if ($response->getStatusCode() >= 300) {
                    throw new \Exception('API returned a non-successful status code: ' . $response->getStatusCode()); //debug the message
                }

//                $apiResponse = $response->toArray();
//                $html = $apiResponse['answer'] ?? null;
//                $html = str_replace('```html', '', $html);
//                $html = str_replace('```', '', $html);

                $apiResponse = $response->toArray();

//              dump($apiResponse);

                $html = $apiResponse['answer'] ?? null;
                $html = str_replace(['```html', '```'], '', $html);

//                dump($html);


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
