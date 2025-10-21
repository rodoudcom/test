<?php
namespace App\WorkflowRodoud;

/**
 * Retry configuration
 */

class RetryConfig
{
    public int $maxAttempts;
    public float $baseDelay;
    public float $multiplier;

    public function __construct(int $maxAttempts = 1, float $baseDelay = 0.0, float $multiplier = 1.0)
    {
        $this->maxAttempts = max(1, $maxAttempts);
        $this->baseDelay = max(0.0, $baseDelay);
        $this->multiplier = max(1.0, $multiplier);
    }
}
