<?php
namespace App\WorkflowRodoud;

/**
 * Retry configuration
 */

class RetryConfig
{
    public int $maxAttempts = 1;
    public float $baseDelay = 1.0; // seconds
    public float $multiplier = 2.0; // exponential backoff
    public float $maxDelay = 60.0; // max delay in seconds

    public function __construct(
        int $maxAttempts = 1,
        float $baseDelay = 1.0,
        float $multiplier = 2.0,
        float $maxDelay = 60.0
    ) {
        $this->maxAttempts = $maxAttempts;
        $this->baseDelay = $baseDelay;
        $this->multiplier = $multiplier;
        $this->maxDelay = $maxDelay;
    }

    public function getDelay(int $attempt): float
    {
        $delay = $this->baseDelay * pow($this->multiplier, $attempt - 1);
        return min($delay, $this->maxDelay);
    }
}
