<?php

namespace App\WorkflowRodoud\Jobs;

use App\WorkflowRodoud\JobResult;
use App\WorkflowRodoud\WorkflowContext;
use Psr\Log\LoggerInterface;
use Doctrine\ORM\EntityManagerInterface;


class DataProcessor
{
    public function __construct(
        private LoggerInterface        $logger,
        private EntityManagerInterface $entityManager
    )
    {
    }

    public function processProducts(WorkflowContext $context, JobResult $result, array $inputs): array
    {
        sleep(5);
        $this->logger->info('Processing products');

        $products = $inputs['products'] ?? [];

        $processed = [];
        foreach ($products as $productData) {
            $processed[] = [
                'id' => $productData['id'],
                'name' => strtoupper($productData['name']),
                'price' => $productData['price'] * 1.2
            ];
        }

        $result->addLog("Processed " . count($processed) . " products");

        return [
            'products' => $processed,
            'count' => count($processed)
        ];
    }

    public function filterProducts(WorkflowContext $context, JobResult $result, array $inputs): array
    {
        $products = $inputs['products'] ?? [];
        $minPrice = $inputs['min_price'] ?? 0;

        $filtered = array_filter($products, fn($p) => $p['price'] >= $minPrice);
        $filtered = array_values($filtered);

        $this->logger->info("Filtered to " . count($filtered) . " products");

        return [
            'products' => $filtered,
            'count' => count($filtered),
            'min_price' => $minPrice
        ];
    }
}
