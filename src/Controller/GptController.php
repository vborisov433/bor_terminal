<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GptController extends AbstractController
{
    private HttpClientInterface $http;

    public function __construct(HttpClientInterface $http)
    {
        $this->http = $http;
    }

    #[Route('/gpt', name: 'app_gpt')]
    public function index(): JsonResponse
    {
        $question = <<<TEXT
        read
        https://www.cnbc.com/2025/08/04/berkshire-shares-dip-after-earnings-decline-lack-of-buybacks-disappoint-investors-.html
        
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

        $payload = ['question' => $question];

        try {
            $response = $this->http->request('POST', 'http://localhost:5000/api/ask-gpt', [
                'json' => $payload,
            ]);

            $data = $response->toArray();

            $answer = $data['answer'] ?? '';

            // Extract only the JSON part
            $start = strpos($answer, '{');
            $end = strrpos($answer, '}');
            if ($start === false || $end === false || $end <= $start) {
                return $this->json([
                    'error' => 'Could not extract JSON from answer',
                    'raw' => $answer,
                ], 500);
            }

            $jsonString = substr($answer, $start, $end - $start + 1);

            $json = json_decode($jsonString, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->json([
                    'error' => 'Extracted string is not valid JSON',
                    'raw' => $jsonString,
                    'answer' => $answer,
                ], 500);
            }

            return $this->json($json);

        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'Failed to reach GPT server',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
