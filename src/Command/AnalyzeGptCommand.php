<?php

namespace App\Command;

use App\Entity\MarketAnalysis;
use App\Entity\NewsArticleInfo;
use App\Entity\NewsItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:analyze-gpt',
    description: 'Add a short description for your command',
)]
class AnalyzeGptCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private HttpClientInterface $http
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $repo = $this->em->getRepository(NewsItem::class);

        $ago = new \DateTimeImmutable('-30 days');
        $newsItems = $repo->createQueryBuilder('n')
            ->where('n.analyzed = :analyzed')
            ->andWhere('n.date >= :startDate') // Using the 'date' field from your Entity
            ->setParameter('analyzed', false)
            ->setParameter('startDate', $ago)
            ->orderBy('n.id', 'DESC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        $total = count($newsItems);
        if ($total === 0) {
            $io->success('No new news items found to analyze.');
            return self::SUCCESS;
        }

        $io->progressStart($total);

        foreach ($newsItems as $newsItem) {
            $question = <<<TEXT
from NEWS title: 
{$newsItem->getTitle()}
from NEWS content: 
{$newsItem->getContent()}

for markets: dow, audjpy, audusd, dxy, fed interest rate, dax, cac 40
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
            try {
                // 1. Send Request
                // Pass 'false' to toArray so 500 errors don't throw immediately, letting us handle the message
                $response = $this->http->request('POST', 'http://localhost:5000/api/ask-gpt', [
                    'json' => ['question' => $question],
                ]);

                $statusCode = $response->getStatusCode();
                $data = $response->toArray(false);

                // 2. Handle API Errors (HTTP 500 or JSON status 'error')
                if ($statusCode !== 200 || ($data['status'] ?? 'success') === 'error') {
                    throw new \RuntimeException($data['message'] ?? "API HTTP $statusCode");
                }

                $answer = $data['answer'] ?? '';

                // 3. Handle Logic Errors (API returned "Error: ...")
                if (str_starts_with($answer, 'Error')) {
                    throw new \RuntimeException("GPT returned error: $answer");
                }

                // 4. Extract JSON
                $start = strpos($answer, '{');
                $end = strrpos($answer, '}');
                if ($start === false || $end === false || $end <= $start) {
                    // Throwing here sends us to the catch block to mark as failed
                    throw new \RuntimeException("No JSON found in response");
                }

                $jsonString = substr($answer, $start, $end - $start + 1);
                $json = json_decode($jsonString, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException('Malformed JSON received');
                }

                // --- SUCCESS PATH ---
                $newsItem->setGptAnalysis($json);
                $newsItem->setAnalyzed(true);

                // Generate the related entities
                $this->completeNewsItem($newsItem, $io);

                $this->em->persist($newsItem);
                $this->em->flush();

                $io->note('Analyzed: ' . $newsItem->getTitle());

            } catch (\Throwable $e) {
                // --- FAILURE PATH ---
                // We caught an error (API down, Invalid JSON, 503, etc.)
                $io->error('Failed: ' . $newsItem->getTitle() . ' - ' . $e->getMessage());

                // [CRITICAL STEP] Mark as analyzed so the QueryBuilder skips it next time
                $newsItem->setAnalyzed(true);

                // Store the error details in the JSON field for debugging later
                $newsItem->setGptAnalysis([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'timestamp' => date('c')
                ]);

                // Optional: You might want to leave 'completed' as false to distinguish successful vs failed items
                $newsItem->setCompleted(false);

                $this->em->persist($newsItem);
                $this->em->flush();
            }

            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->success('All news items processed.');
        return self::SUCCESS;
    }

    private function completeNewsItem(NewsItem $newsItem, SymfonyStyle $io): void
    {
        $gpt = $newsItem->getGptAnalysis();

        if (!$gpt || !isset($gpt['markets'], $gpt['article_info'])) {
            $io->warning('Skipping NewsItem#'.$newsItem->getId().': missing gptAnalysis');
            $newsItem->setCompleted(true);
            $this->em->persist($newsItem);
            return;
        }

        // Remove old MarketAnalysis for this news item to avoid duplicates
        $existingAnalyses = $newsItem->getMarketAnalyses() ?? [];
        foreach ($existingAnalyses as $ma) {
            $this->em->remove($ma);
        }

        // Create new MarketAnalysis entities
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

        // NewsArticleInfo: create or update
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
        $this->em->persist($newsItem);
    }
}
