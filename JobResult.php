<?php

namespace App\WorkflowRodoud;


/**
 * Job execution result with structured output
 */
class JobResult
{
    public string $stepId;
    public string $jobName;
    public array $output = []; // Structured output with named indices
    public array $errors = [];
    public array $logs = [];
    public float $startTime;
    public float $endTime;
    public float $duration;
    public array $input;
    public string $status;
    public int $attemptNumber = 1;

    public function __construct(string $stepId, string $jobName)
    {
        $this->stepId = $stepId;
        $this->jobName = $jobName;
        $this->startTime = microtime(true);
        $this->status = 'pending';
    }

    public function finish(array $output): void
    {
        $this->endTime = microtime(true);
        $this->duration = $this->endTime - $this->startTime;
        $this->output = $output;
        $this->status = empty($this->errors) ? 'success' : 'failed';
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    public function addLog(string $log): void
    {
        $this->logs[] = $log;
    }

    public function getOutputValue(string $key, mixed $default = null): mixed
    {
        return $this->output[$key] ?? $default;
    }
}