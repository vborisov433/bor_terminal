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

    #[Route('/test', name: 'debug_analyze_gpt', methods: ['GET'])]
    public function debug(): JsonResponse
    {
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
from NEWS: 
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
        try {
            // Note: Use a short timeout for debug endpoint so it fails fast if server is down
            $response = $this->http->request('POST', 'http://localhost:5000/api/ask-gpt', [
                'json' => ['prompt' => $question], // Changed 'question' to 'prompt' to match standard flask naming, revert if your python uses 'question'
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();
            $logs[] = "API Response Code: $statusCode";

            if ($statusCode !== 200) {
                throw new \RuntimeException('API returned non-200 status code: ' . $statusCode);
            }

            $data = $response->toArray();
            $logs[] = 'API Raw Response received.';

            // Handle different API response structures (e.g. 'answer' vs 'response')
            $answer = $data['answer'] ?? $data['response'] ?? '';

            if (empty($answer)) {
                $logs[] = "Full API Response dump: " . json_encode($data);
                throw new \RuntimeException('API response did not contain "answer" or "response" key.');
            }

        } catch (\Throwable $e) {
            return $this->json([
                'status' => 'api_error',
                'step' => 'External API Call',
                'error' => $e->getMessage(),
                'news_item_id' => $newsItem->getId(),
                'logs' => $logs
            ], 502);
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