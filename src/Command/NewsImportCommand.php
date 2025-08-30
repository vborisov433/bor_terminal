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
    name: 'app:news-import',
    description: 'Add a short description for your command',
)]
class NewsImportCommand extends Command
{
    public function __construct(
        private HttpClientInterface    $httpClient,
        private EntityManagerInterface $em
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->section('Fetching latest news...');
        $response = $this->httpClient->request('GET', 'http://15.0.1.98:3000/api/latest-news');
        $newsArray = $response->toArray();

        usort($newsArray, function($a, $b) {
            return $b['index'] <=> $a['index']; // Descending: ... 3, 2, 1
        });

        $repo = $this->em->getRepository(NewsItem::class);

        $inserted = 0;
        $skipped = 0;

        foreach ($newsArray as $newsData) {
            if ($repo->findOneBy(['link' => $newsData['link']])) {
                $skipped++;
                continue;
            }

            $entity = new NewsItem();
            $entity->setTitle($newsData['title']);
            $entity->setLink($newsData['link']);
            $entity->setDate(new \DateTimeImmutable($newsData['date']));

            $this->em->persist($entity);
            $inserted++;
        }

        $this->em->flush();

        $io->success("Imported $inserted new news items.");
        $io->note("Skipped $skipped duplicate items.");

        return self::SUCCESS;

    }
}
