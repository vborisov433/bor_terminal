<?php

namespace App\Command;

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
    name: 'analyze-gpt',
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
        $newsItems = $repo->findBy(['analyzed' => false]);
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
                $response = $this->http->request('POST', 'http://localhost:5000/api/ask-gpt', [
                    'json' => ['question' => $question],
                ]);
                $data = $response->toArray();
                $answer = $data['answer'] ?? '';

                // Extract the JSON block only as before
                $start = strpos($answer, '{');
                $end = strrpos($answer, '}');
                if ($start === false || $end === false || $end <= $start) {
                    throw new \RuntimeException("Could not extract JSON from answer for link: " . $newsItem->getLink());
                }
                $jsonString = substr($answer, $start, $end - $start + 1);
                $json = json_decode($jsonString, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException('Malformed JSON for link: ' . $newsItem->getLink());
                }

                $newsItem->setGptAnalysis($json);
                $newsItem->setAnalyzed(true);
                $io->note('Analyzed: ' . $newsItem->getTitle());
            } catch (\Throwable $e) {
                $io->error('Failed: ' . $newsItem->getTitle() . ' - ' . $e->getMessage());
            }

            $io->progressAdvance();
        }

        $this->em->flush();

        $io->progressFinish();
        $io->success('All news items processed.');
        return self::SUCCESS;
    }
}
