<?php

namespace App\WorkflowRodoud\Jobs;

use App\WorkflowRodoud\JobResult;
use App\WorkflowRodoud\WorkflowContext;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function Amp\delay;


class WordPress
{
    public function __construct(
        private LoggerInterface     $logger,
        private HttpClientInterface $httpClient
    )
    {
    }

    public function getProducts(WorkflowContext $context, JobResult $result, array $inputs): array
    {
        $this->logger->info('Fetching WordPress products');

        $apiUrl = $inputs['api_url'] ?? 'https://default.com/api';
        $limit = $inputs['limit'] ?? 10;

        // Use Symfony's HTTP client
//        $response = $this->httpClient->request('GET', $apiUrl . '/products', [
//            'query' => ['limit' => $limit]
//        ]);
//
//        $products = $response->toArray();

        $products = [["name" => "test", "price" => 100, "id" => 1, "image" => "https://default.com/image.jpg"]];

        if ($apiUrl == 'https://store2.com') {
            $products[] = ["name" => "test2", "price" => 400, "id" => 2, "image" => "https://default.com/image2.jpg"];
        } else {
            delay(3);
        }

        $result->addLog("Fetched " . count($products) . " products from {$apiUrl}");

        return [
            'products' => $products,
            'count' => count($products),
            'source' => $apiUrl
        ];
    }

    public function searchProducts(WorkflowContext $context, JobResult $result, array $inputs): array
    {
        $query = $inputs['query'] ?? '';
        $this->logger->info("Searching for: {$query}");

        return [
            'products' => [],
            'query' => $query,
            'count' => 0
        ];
    }
}