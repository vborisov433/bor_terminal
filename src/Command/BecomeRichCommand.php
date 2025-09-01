<?php

namespace App\Command;

use App\Entity\MarketAnalysis;
use App\Entity\NewsArticleInfo;
use App\Entity\NewsItem;
use App\Entity\Service;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function Symfony\Component\DependencyInjection\Loader\Configurator\env;

#[AsCommand(
    name: 'app:become-rich',
    description: 'Add a short description for your command',
)]
class BecomeRichCommand extends Command
{
    /**
     * @param OutputInterface $output
     * @return mixed
     */
    public function updateBornewsService()
    {
        $repo = $this->em->getRepository(Service::class);
        $service = $repo->findOneBy(['name' => 'bornews']);

        if (!$service){
            $service = new Service();
            $service->setName('bornews');
            $service->setLastSeen(new \DateTime());
            $service->setData('Initialized or updated data');
        }
        $service->setLastSeen(new \DateTime());

        $this->em->persist($service);
        $this->em->flush();

        return $repo;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('arg1', InputArgument::OPTIONAL, 'Argument description')
            ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description')
        ;
    }

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $em,
        private ManagerRegistry $doctrine
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $repo = $this->em->getRepository(NewsItem::class);

        // 1. Import Section
        $io->section('Fetching latest news...');
        $response = $this->httpClient->request('GET', 'http://15.0.1.98:3000/api/latest-news',
             [
                'timeout' => 900
            ]);
        $newsArray = $response->toArray();

        if (is_array($newsArray)){
            $this->updateBornewsService();
        }

        usort($newsArray, fn($a, $b) => $b['index'] <=> $a['index']);

        $inserted = 0;
        $skipped = 0;
        $analyzed = 0;

        $newItems = [];
        foreach ($newsArray as $newsData) {
            if ($repo->findOneBy(['link' => $newsData['link']])) {
                $skipped++;
                continue;
            }
            $entity = new NewsItem();
            $entity->setTitle($newsData['title']);
            $entity->setLink($newsData['link']);
            $entity->setDate(new \DateTimeImmutable($newsData['date']));

            try {
                $this->em->persist($entity);
                $this->em->flush();
                $newItems[] = $entity;
                $inserted++;
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
                $skipped++;
                continue;
            }

            $newItems[] = $entity;
            $inserted++;
        }
        $io->success("Imported $inserted new news items.");
        $io->note("Skipped $skipped duplicate items.");

        // 2. Analyze Section (only newly inserted items)
        if (empty($newItems)) {
            $io->success('No new news items to analyze.');
//            return self::SUCCESS;
        }
        $io->section('Analyzing news with GPT...');
        $io->progressStart(count($newItems));

        foreach ($newItems as $newsItem) {
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
                $data = $this->requestWithRetries(function() use ($question) {
                    $response = $this->httpClient->request('POST', 'http://localhost:5000/api/ask-gpt?model=gpt-4.1', [
                        'json' => ['question' => $question],
                        // optionally, set timeout: 'timeout' => 40
                    ]);
                    return $response->toArray();
                }, 3, 3);
                $answer = $data['answer'] ?? '';

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

                $this->completeNewsItem($newsItem, $io);

                $this->em->persist($newsItem);
                $this->em->flush();

                $io->note('Analyzed: ' . $newsItem->getTitle());
                $analyzed++;
            }
            catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
                $this->em->clear(); // optional: clear UoW to avoid memory leaks
                $this->em = $this->doctrine->resetManager(); // reopen EntityManager
                continue;
            }
            catch (\Throwable $e) {
                $io->error('Failed: ' . $newsItem->getTitle() . ' - ' . $e->getMessage());
            }
            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->success("Analyzed $analyzed new news items.");

        $this->runAnalyzeGpt($io);

        return self::SUCCESS;
    }

    public function runAnalyzeGpt(SymfonyStyle $io): void
    {
        $application = $this->getApplication();
        if ($application) {
            $application->setAutoExit(false); // Prevents Symfony from shutting down the PHP process

            $input = new ArrayInput([
                'command' => 'app:analyze-gpt', // the name from #[AsCommand()]
                // add any arguments or options if needed:
                // '--optionName' => 'value'
            ]);

            $output = new BufferedOutput();
            $returnCode = $application->run($input, $output);

            $io->text($output->fetch());

            if ($returnCode === 0) {
                $io->success('Analyze GPT command completed successfully.');
            } else {
                $io->error('Analyze GPT command failed.');
            }
        }
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

        $existingAnalyses = $newsItem->getMarketAnalyses() ?? [];
        foreach ($existingAnalyses as $ma) {
            $this->em->remove($ma);
        }
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

    private function requestWithRetries(callable $fetch, int $maxAttempts = 3, float $retryDelay = 1.0)
    {
        $lastException = null;
        for ($i = 1; $i <= $maxAttempts; $i++) {
            try {
                return $fetch();
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($i < $maxAttempts) {
                    sleep($retryDelay); // or usleep for sub-second
                }
            }
        }
        throw $lastException;
    }
}
