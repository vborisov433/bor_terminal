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
        // Only process unanalyzed items
//        $newsItems = $repo->findBy(['analyzed' => false]);

        $newsItems = $repo->findBy(
            ['analyzed' => false],
            ['id' => 'DESC'],
            100, // Limit to 100 items
        );

        $total = count($newsItems);
        if ($total === 0) {
            $io->success('No new news items found to analyze.');
            return self::SUCCESS;
        }

        $io->progressStart($total);

        foreach ($newsItems as $newsItem) {
            // Build the question/prompt
            $question = <<<TEXT
read
{$newsItem->getLink()}

for markets: dow , audjpy , audusd , dxy , fed interest rate
give: market sentiment , short summary
return in json
for each market in format:

"markets": [
{
   "magnitude": "", // from 1-10
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
   "news_surprise_index": 0,
   "economy_impact": 0,
   "macro_keyword_heatmap": [],
   "summary": ""
}
TEXT;
            try {
                $response = $this->http->request('POST', 'http://localhost:5000/api/ask-gpt?model=gpt-4.1', [
                    'json' => ['question' => $question],
                ]);
                $data = $response->toArray();
                $answer = $data['answer'] ?? '';

                // Extract the JSON block only as before
                $start = strpos($answer, '{');
                $end = strrpos($answer, '}');
                if ($start === false || $end === false || $end <= $start) {
                    throw new \RuntimeException("Could not extract JSON from answer for link: " . json_encode($answer));
                }
                $jsonString = substr($answer, $start, $end - $start + 1);
                $json = json_decode($jsonString, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException('Malformed JSON for link: ' . $newsItem->getLink());
                }

                $newsItem->setGptAnalysis($json);
                $newsItem->setAnalyzed(true);

                $this->completeNewsItem($newsItem, $io);

                $this->em->persist($newsItem);
                $this->em->flush();

                $io->note('Analyzed: ' . $newsItem->getTitle());
            } catch (\Throwable $e) {
                $io->error('Failed: ' . $newsItem->getTitle() . ' - ' . $e->getMessage());
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
