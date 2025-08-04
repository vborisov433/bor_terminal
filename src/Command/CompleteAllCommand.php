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

#[AsCommand(
    name: 'complete-all',
    description: 'Add a short description for your command',
)]
class CompleteAllCommand extends Command
{
    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('arg1', InputArgument::OPTIONAL, 'Argument description')
            ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $newsRepo = $this->em->getRepository(NewsItem::class);
        $newsItems = $newsRepo->findBy(['completed' => false]);

        if (empty($newsItems)) {
            $io->info('No NewsItems needing completion.');
            return self::SUCCESS;
        }

        $io->progressStart(count($newsItems));

        foreach ($newsItems as $newsItem) {
            $gpt = $newsItem->getGptAnalysis();

            if (!$gpt || !isset($gpt['markets'], $gpt['article_info'])) {
                $io->warning('Skipping NewsItem#'.$newsItem->getId().': missing gptAnalysis');
                $newsItem->setCompleted(true);
                $this->em->persist($newsItem);
                $io->progressAdvance();
                continue;
            }

            // Remove old MarketAnalysis for this news item, if you want to avoid duplicates
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

            $io->progressAdvance();
        }

        $this->em->flush();

        $io->progressFinish();
        $io->success('Finished populating MarketAnalysis and NewsArticleInfo for NewsItems.');
        return self::SUCCESS;
    }
}
