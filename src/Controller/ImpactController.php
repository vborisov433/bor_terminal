<?php

namespace App\Controller;

use App\Entity\MarketAnalysis;
use App\Entity\NewsItem;
use App\Repository\NewsItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ImpactController extends AbstractController
{
    #[Route('/impact', name: 'news_impact', methods: ['GET'])]
    public function __invoke(
        Request $request,
        EntityManagerInterface $em,
        NewsItemRepository $newsItemRepository
    ) {
        // Read slider values (1..10). Defaults to midpoint.
        $newsSurpriseIndex = max(1, min(10, (int) $request->query->get('newsSurpriseIndex', 5)));
        $economyImpact     = max(1, min(10, (int) $request->query->get('economyImpact', 5)));

        // Normalize to 0..1 weights
        $wSurprise = $newsSurpriseIndex / 10.0;
        $wEconomy  = $economyImpact / 10.0;


        $qb = $em->createQueryBuilder();
        $latestNews = $qb->select('n', 'info')
            ->from(NewsItem::class, 'n')
            ->leftJoin('n.articleInfo', 'info')
            ->join('n.marketAnalyses', 'ma')
//            ->addSelect('SUM(ma.magnitude * :wSurprise * :wEconomy) AS HIDDEN weightedScore')
            ->where('info.economyImpact >= :economyImpact')
            ->andWhere('info.newsSurpriseIndex >= :newsSurpriseIndex')
            ->groupBy('n.id, info.id')
//            ->orderBy('weightedScore', 'DESC')
            ->setMaxResults(500)
//            ->setParameter('wSurprise', $wSurprise)
//            ->setParameter('wEconomy', $wEconomy)
            ->setParameter('economyImpact', $economyImpact)
            ->setParameter('newsSurpriseIndex', $newsSurpriseIndex)
            ->getQuery()
            ->getResult();


        // Latest 100 NewsItems
//        $latestNews = $newsItemRepository->createQueryBuilder('n')
//            ->orderBy('n.createdAt', 'DESC')
//            ->addOrderBy('n.date', 'DESC')
//            ->setMaxResults(100)
//            ->getQuery()
//            ->getResult();

        if (count($latestNews) === 0) {
            return $this->render('impact/index.html.twig', [
                'newsSurpriseIndex' => $newsSurpriseIndex,
                'economyImpact'     => $economyImpact,
                'chart' => [
                    'labels' => [],
                    'scores' => [],
                    'colors' => [],
                ],
                'marketsTable' => [],
                'totalNews' => 0,
            ]);
        }

        // IDs for IN clause
        $newsIds = array_map(static fn(NewsItem $n) => $n->getId(), $latestNews);

        // Fetch MarketAnalysis joined to the selected NewsItems
        $qb = $em->createQueryBuilder();
        $analyses = $qb->select('ma, n')
            ->from(MarketAnalysis::class, 'ma')
            ->join('ma.newsItem', 'n')
            ->where($qb->expr()->in('n.id', ':ids'))
            ->setParameter('ids', $newsIds)
            ->getQuery()
            ->getResult();

        // Aggregate per normalized market
        $markets = []; // normalizedMarket => stats
        foreach ($analyses as $ma) {
            if (!$ma instanceof MarketAnalysis) {
                continue;
            }

            $rawMarket = (string) ($ma->getMarket() ?? '');
            $market    = $this->normalizeMarket($rawMarket); // <<<<<<<<<<<<<< NEW

            $sentiment = strtolower(trim((string) $ma->getSentiment()));
            $magnitude = (int) $ma->getMagnitude();

            if ($market === '' || $magnitude <= 0) {
                continue;
            }

            $sign = match ($sentiment) {
                'bullish' =>  1,
                'bearish' => -1,
                'neutral' =>  0,
                default   =>  0,
            };


            $weight = $magnitude * $wSurprise * $wEconomy;
            $contribution = $sign * $weight;

            if (!isset($markets[$market])) {
                $markets[$market] = [
                    'sum'            => 0.0,
                    'weight'         => 0.0,
                    'counts'         => ['positive' => 0, 'negative' => 0, 'neutral' => 0],
                    'totalMagnitude' => 0,
                    'items'          => 0,
                ];
            }

            $markets[$market]['sum']            += $contribution;
            $markets[$market]['weight']         += $weight;
            $markets[$market]['totalMagnitude'] += $magnitude;
            $markets[$market]['items']          += 1;

            if ($sign > 0) {
                $markets[$market]['counts']['positive']++;
            } elseif ($sign < 0) {
                $markets[$market]['counts']['negative']++;
            } else {
                $markets[$market]['counts']['neutral']++;
            }
        }

        // Prepare chart/table
        $labels = [];
        $scores = [];
        $colors = [];
        $marketsTable = [];

        // Sort by average descending
        uasort($markets, function ($a, $b) {
            $avgA = ($a['weight'] > 0) ? $a['sum'] / $a['weight'] : 0;
            $avgB = ($b['weight'] > 0) ? $b['sum'] / $b['weight'] : 0;
            return $avgB <=> $avgA;
        });

        foreach ($markets as $market => $data) {
            if ($data['items'] < 10) {
                continue;
            }

            $avg    = ($data['weight'] > 0) ? ($data['sum'] / $data['weight']) : 0.0; // -1..+1
            $scaled = round($avg * 100, 1); // -100..+100

            $labels[] = $market;
            $scores[] = $scaled;
            $colors[] = $scaled > 5  ? 'rgba(25, 135, 84, 0.75)'
                : ($scaled < -5 ? 'rgba(220, 53, 69, 0.75)'
                    : 'rgba(108, 117, 125, 0.75)');

            $marketsTable[] = [
                'market'         => $market,
                'avgSentiment'   => $avg,
                'scaledScore'    => $scaled,
                'counts'         => $data['counts'],
                'items'          => $data['items'],
//                'totalMagnitude' => $data['totalMagnitude'],
            ];
        }

        return $this->render('impact/index.html.twig', [
            'newsSurpriseIndex' => $newsSurpriseIndex,
            'economyImpact'     => $economyImpact,
            'chart' => [
                'labels' => $labels,
                'scores' => $scores,
                'colors' => $colors,
            ],
            'marketsTable' => $marketsTable,
            'totalNews' => count($latestNews),
        ]);
    }

    /**
     * Normalize market names so common variants are combined.
     * - Removes parenthetical tickers: "Dow Jones Industrial Average (DOW)" -> "Dow Jones Industrial Average"
     * - Case-insensitive
     * - Collapses punctuation/whitespace
     * - Maps aliases to a canonical label
     */
    private function normalizeMarket(string $market): string
    {
        // Trim and collapse whitespace
        $s = trim(preg_replace('/\s+/', ' ', $market));

        // Remove "(...)" ticker or descriptors
        $sNoParen = preg_replace('/\s*\([^)]+\)\s*/u', '', $s);

        // Lowercase key and simplify punctuation for matching
        $key = mb_strtolower($sNoParen, 'UTF-8');
        $key = str_replace(['.', ',', '_', '-'], ' ', $key);
        $key = preg_replace('/\s+/u', ' ', $key);
        $key = trim($key);
        $key = strtolower($key);

        // USE ALL LOWERCASE KEYS - ON LEFT SIDE ONLY
        $dict = [
            'dow'                              => 'Dow',
            'dow jones'                        => 'Dow',
            'dow jones industrial average'     => 'Dow',
            'dow jones industrial average dow' => 'Dow',
            'dji'                              => 'Dow',
            'djia'                              => 'Dow',

            'aud usd' => 'AUDUSD',
            'audusd' => 'AUDUSD',
            'aud/usd' => 'AUDUSD',
            'audjpy' => 'AUDJPY',
            'aud/jpy' => 'AUDJPY',

            'fed interest rate' => 'Fed',
            'federal reserve interest rate' => 'Fed',
            'us fed interest rate' => 'Fed',
            'federal reserve interest rate policy' => 'Fed',
            'fed interest rate decision' => 'Fed',
            'federal reserve interest rate expectations' => 'Fed',
            'fed interest rate outlook' => 'Fed',

            'dxy' => 'US Dollar',
            'us dollar index' => 'US Dollar',

        ];

        if (isset($dict[$key])) {
            return $dict[$key];
        }

        // No match: return cleaned string (preserve original casing without parentheses)
        return $sNoParen !== null ? trim($sNoParen) : trim($market);
    }

}
