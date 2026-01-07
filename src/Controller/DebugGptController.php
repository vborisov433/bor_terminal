<?php

namespace App\Controller;

use App\Entity\MarketAnalysis;
use App\Entity\NewsArticleInfo;
use App\Entity\NewsItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DebugGptController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private HttpClientInterface $http
    ) {}

    #[Route('/reset-analysis', name: 'debug_reset_analysis', methods: ['GET'])]
    public function resetAnalysis(): JsonResponse
    {
        $logs = [];
        $logs[] = 'Starting Analysis Reset for the last 30 days...';

        $dateThreshold = (new \DateTimeImmutable())->modify('-30 days');
        $logs[] = 'Date threshold set to: ' . $dateThreshold->format('Y-m-d H:i:s');

        try {
            // 1. Delete MarketAnalysis
            $deletedMarketAnalysis = $this->em->createQuery(
                'DELETE App\Entity\MarketAnalysis m 
                 WHERE m.newsItem IN (
                     SELECT n.id FROM App\Entity\NewsItem n WHERE n.date >= :date
                 )'
            )->setParameter('date', $dateThreshold)->execute();

            $logs[] = "Deleted {$deletedMarketAnalysis} MarketAnalysis records.";

            // 2. Delete NewsArticleInfo
            $deletedArticleInfo = $this->em->createQuery(
                'DELETE App\Entity\NewsArticleInfo i 
                 WHERE i.newsItem IN (
                     SELECT n.id FROM App\Entity\NewsItem n WHERE n.date >= :date
                 )'
            )->setParameter('date', $dateThreshold)->execute();

            $logs[] = "Deleted {$deletedArticleInfo} NewsArticleInfo records.";

            // 3. Reset NewsItem flags
            $updatedNewsItems = $this->em->createQuery(
                'UPDATE App\Entity\NewsItem n 
                 SET n.analyzed = false, n.completed = false, n.gptAnalysis = null 
                 WHERE n.date >= :date'
            )->setParameter('date', $dateThreshold)->execute();

            $logs[] = "Reset flags for {$updatedNewsItems} NewsItem records.";

            return $this->json([
                'status' => 'success',
                'message' => 'Analysis data reset successfully.',
                'stats' => [
                    'market_analysis_deleted' => $deletedMarketAnalysis,
                    'article_info_deleted' => $deletedArticleInfo,
                    'news_items_reset' => $updatedNewsItems,
                ],
                'logs' => $logs
            ]);

        } catch (\Throwable $e) {
            return $this->json([
                'status' => 'error',
                'message' => 'Failed to reset analysis data.',
                'error' => $e->getMessage(),
                'logs' => $logs
            ], 500);
        }
    }

    #[Route('/test', name: 'debug_analyze_gpt', methods: ['GET'])]
    public function debug(): JsonResponse
    {
        $startTime = microtime(true);
        $logs = [];
        $logs[] = 'Starting Debug Process...';

        // 1. Check Database for Items
        try {
            $repo = $this->em->getRepository(NewsItem::class);
            // Limit to 1 for debugging purposes to prevent browser timeouts
            $newsItem = $repo->findOneBy(['analyzed' => false], ['id' => 'DESC']);

            if (!$newsItem) {
                return $this->json([
                    'status' => 'warning',
                    'message' => 'No unanalyzed news items found in the database.',
                    'logs' => $logs
                ]);
            }
            $logs[] = "Found NewsItem ID: {$newsItem->getId()} - Title: {$newsItem->getTitle()}";
        } catch (\Throwable $e) {
            return $this->json([
                'status' => 'critical_error',
                'step' => 'Database Check',
                'error' => $e->getMessage(),
                'logs' => $logs
            ], 500);
        }

        // 2. Prepare the Question
        $question = <<<TEXT
from NEWS title: 
{$newsItem->getTitle()}
from NEWS content: 
{$newsItem->getContent()}

for markets: dow , audjpy , audusd , dxy , fed interest rate
give: market sentiment , short summary
return in json
for each market in format:

"markets": [
{
   "magnitude": "", // classify from 1 min to 10 max
   "market": "",
   "sentiment": "Bearish or Bullish or Neutral",
   "reason": "...",
   "keywords": [],
   "categories": []
}
],
"article_info": {
   "has_market_impact": false or true,
   "title_headline": "",
   "news_surprise_index": 0, // classify from 1 min to 10 max
   "economy_impact": 0, // classify from 1 min to 10 max
   "macro_keyword_heatmap": [],
   "summary": ""
}
TEXT;

        // 3. Call External API
        $logs[] = 'Attempting to call http://localhost:5000/api/ask-gpt...';

            // Note: Use a short timeout for debug endpoint so it fails fast if server is down
            $response = $this->http->request('POST', 'http://localhost:5000/api/ask-gpt', [
                'json' => ['prompt' => $question],
                'timeout' => 120,
            ]);

            $statusCode = $response->getStatusCode();
            $logs[] = "API Response Code: $statusCode";

            if ($statusCode !== 200) {

                dd($question);
                // [FIX 2] readable error reporting
                $msg = $data['message'] ?? $data['error'] ?? 'Error';
                return $this->json([
                    'status' => 'api_rejected',
                    'http_code' => $statusCode,
                    'message' => $msg,
                    'logs' => $logs
                ], $statusCode);
            }

            $data = $response->toArray(false);
            $logs[] = 'API Raw Response received.';

            // Handle different API response structures (e.g. 'answer' vs 'response')
            $answer = $data['answer'] ?? $data['response'] ?? '';

            if (str_starts_with($answer, 'Error') || str_contains($answer, 'AUTHENTICATION ERROR')) {
                return $this->json([
                    'status' => 'python_logic_error',
                    'message' => $answer,
                    'logs' => $logs
                ], 500);
            }

            if (empty($answer)) {
                $logs[] = "Full API Response dump: " . json_encode($data);
                throw new \RuntimeException('API response did not contain "answer" or "response" key.');
            }

        // 4. Parse JSON Logic
        try {
            $start = strpos($answer, '{');
            $end = strrpos($answer, '}');

            if ($start === false || $end === false || $end <= $start) {
                throw new \RuntimeException("Could not find valid JSON start/end braces in API response.");
            }

            $jsonString = substr($answer, $start, $end - $start + 1);
            $parsedJson = json_decode($jsonString, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('JSON Decode Error: ' . json_last_error_msg());
            }

            $logs[] = 'JSON successfully parsed.';

        } catch (\Throwable $e) {

            return $this->json([
                'status' => 'parsing_error',
                'step' => 'JSON Extraction',
                'raw_answer_snippet' => substr($answer, 0, 500) . '...',
                'error' => $e->getMessage(),
                'logs' => $logs
            ], 500);
        }

        // 5. Save to Database
        try {
            $newsItem->setGptAnalysis($parsedJson);
            $newsItem->setAnalyzed(true);

            $this->completeNewsItem($newsItem, $logs);

            $this->em->persist($newsItem);
            $this->em->flush();
            $logs[] = 'Database updated successfully.';

        } catch (\Throwable $e) {
            return $this->json([
                'status' => 'database_error',
                'step' => 'Saving Entities',
                'error' => $e->getMessage(),
                'logs' => $logs
            ], 500);
        }

        $endTime = microtime(true);
        $executionTime = round($endTime - $startTime, 4); // Round to 4 decimal places
        $logs[] = "Process finished in {$executionTime} seconds.";

        return $this->json([
            'status' => 'success',
            'news_item_id' => $newsItem->getId(),
            'parsed_data' => $parsedJson,
            'logs' => $logs
        ]);
    }

    private function completeNewsItem(NewsItem $newsItem, array &$logs): void
    {
        $gpt = $newsItem->getGptAnalysis();

        if (!$gpt || !isset($gpt['markets'], $gpt['article_info'])) {
            $logs[] = "Warning: Missing 'markets' or 'article_info' in GPT data. Marking completed anyway.";
            $newsItem->setCompleted(true);
            return;
        }

        // Remove old MarketAnalysis
        $existingAnalyses = $newsItem->getMarketAnalyses() ?? [];
        foreach ($existingAnalyses as $ma) {
            $this->em->remove($ma);
        }

        // Add New MarketAnalysis
        foreach ($gpt['markets'] as $marketData) {
            $ma = new MarketAnalysis();
            $ma->setNewsItem($newsItem);
            $ma->setMarket($marketData['market'] ?? '');
            $ma->setSentiment($marketData['sentiment'] ?? '');
            $ma->setMagnitude((int)($marketData['magnitude'] ?? 0));
            $ma->setReason($marketData['reason'] ?? '');
            $ma->setKeywords($marketData['keywords'] ?? []);
            $ma->setCategories($marketData['categories'] ?? []);
            $this->em->persist($ma);
        }

        // Update Article Info
        $info = $gpt['article_info'];
        $articleInfo = $newsItem->getArticleInfo() ?? new NewsArticleInfo();
        $articleInfo->setNewsItem($newsItem);
        $articleInfo->setHasMarketImpact((bool)($info['has_market_impact'] ?? false));
        $articleInfo->setTitleHeadline($info['title_headline'] ?? null);
        if (isset($info['news_surprise_index'])) {
            $articleInfo->setNewsSurpriseIndex((int)$info['news_surprise_index']);
        }
        if (isset($info['economy_impact'])) {
            $articleInfo->setEconomyImpact((int)$info['economy_impact']);
        }
        $articleInfo->setMacroKeywordHeatmap($info['macro_keyword_heatmap'] ?? []);
        $articleInfo->setSummary($info['summary'] ?? null);
        $this->em->persist($articleInfo);

        $newsItem->setCompleted(true);
        $logs[] = 'Entities hydration logic completed.';
    }
}