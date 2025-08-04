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
        $payload = ['question' => 'What is the stock market?'];

        try {
            $response = $this->http->request('POST', 'http://localhost:5000/api/ask-gpt', [
                'json' => $payload,
            ]);

            $data = $response->toArray();

            return $this->json([
                'answer' => $data['answer'] ?? 'No answer returned',
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'Failed to reach GPT server',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
