<?php

namespace App\WorkflowRodoud;

/**
 * Step configuration
 */
class StepConfig
{
    public string $id;
    public string $job;
    public array $inputs = [];
    public array $dependsOn = [];
    public bool $parallel = false;
    public bool $stopOnError = true;
    public ?DeciderConfig $decider = null;
    public RetryConfig $retry;

    public function __construct(string $id,  string $job = '')
    {
        $this->id = $id;
        $this->job = $job;
        $this->retry = new RetryConfig();
    }
}


