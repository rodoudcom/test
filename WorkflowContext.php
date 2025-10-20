<?php

namespace App\WorkflowRodoud;

/**
 * Context holds all workflow data and results
 */
class WorkflowContext
{
    private array $globalVars = [];
    private array $stepVars = [];
    private array $results = [];

    public function setGlobal(string $key, mixed $value): void
    {
        $this->globalVars[$key] = $value;
    }

    public function getGlobal(string $key, mixed $default = null): mixed
    {
        return $this->globalVars[$key] ?? $default;
    }

    public function setStepVar(string $stepId, string $key, mixed $value): void
    {
        $this->stepVars[$stepId][$key] = $value;
    }

    public function getStepVar(string $stepId, string $key, mixed $default = null): mixed
    {
        return $this->stepVars[$stepId][$key] ?? $default;
    }

    public function getStepVars(string $stepId): array
    {
        return $this->stepVars[$stepId] ?? [];
    }

    public function setResult(string $stepId, JobResult $result): void
    {
        $this->results[$stepId] = $result;
    }

    public function getResult(string $stepId): ?JobResult
    {
        return $this->results[$stepId] ?? null;
    }

    public function getResultOutput(string $stepId, ?string $key = null, mixed $default = null): mixed
    {
        if (!isset($this->results[$stepId])) {
            return $default;
        }

        if ($key === null) {
            return $this->results[$stepId]->output;
        }

        return $this->results[$stepId]->output[$key] ?? $default;
    }

    public function getAllResults(): array
    {
        return $this->results;
    }

    public function getAllGlobals(): array
    {
        return $this->globalVars;
    }

    /**
     * Resolve input references like ["jobId" => "outputKey"]
     */
    public function resolveInputs(array $inputs): array
    {
        $resolved = [];

        foreach ($inputs as $key => $value) {
            if (is_array($value) && count($value) === 1) {
                // Check if it's a job reference
                $refKey = array_key_first($value);
                $refValue = $value[$refKey];

                if (isset($this->results[$refKey])) {
                    // It's a job result reference
                    $resolved[$key] = $this->getResultOutput($refKey, $refValue);
                } else {
                    $resolved[$key] = $value;
                }
            } else {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }
}