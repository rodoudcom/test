<?php

namespace App\WorkflowRodoud;

/**
 * Step configuration
 */
class StepConfig
{
    public string $id;
    public object $jobProvider; // Can be "ClassName.method" or actual instance
    public string $job;
    public array $inputs = [];
    public array $dependsOn = [];
    public bool $parallel = false;
    public bool $stopOnError = true;
    public ?DeciderConfig $decider = null;
    public RetryConfig $retry;

    public function __construct(string $id, object $jobProvider, string $job = '')
    {
        $this->id = $id;
        $this->jobProvider = $jobProvider;
        $this->job = $job;
        $this->retry = new RetryConfig();
    }
}


