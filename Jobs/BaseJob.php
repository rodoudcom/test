<?php

namespace App\WorkflowRodoud\Jobs;

use App\WorkflowRodoud\Contracts\JobInterface;
use App\WorkflowRodoud\Attributes\Job;
use App\WorkflowRodoud\RetryConfig;
use App\WorkflowRodoud\WorkflowContext;
use DateTime;
use ReflectionClass;

abstract class BaseJob implements JobInterface
{
    public string $id = "";
    public ?WorkflowContext $context = null;
    public ?RetryConfig $retryConfig = null;

    public function getName(): string
    {

        $reflection = new ReflectionClass($this);
        $attributes = $reflection->getAttributes(Job::class);

        if (!empty($attributes)) {
            $jobAttribute = $attributes[0]->newInstance();
            return $jobAttribute->name;
        }

        return $this->id;
    }

    private function getDescription(): string
    {

        $reflection = new ReflectionClass($this);
        $attributes = $reflection->getAttributes(Job::class);

        if (!empty($attributes)) {
            $jobAttribute = $attributes[0]->newInstance();
            return $jobAttribute->description;
        }

        return '';
    }


    public function validateInputs(array $inputs = []): bool
    {
        return true;
    }

    public function setId(string $id): JobInterface
    {
        $this->id = $id;
        return $this;
    }

    public function setContext(WorkflowContext $context): self
    {
        $this->context = $context;
        return $this;
    }

    abstract public function execute(array $inputs = []): mixed;

    public function addLog(string $log): void
    {
        $this->context->addJobLog($this->id, $log);
    }

    public function addError(string $error): void
    {
        $this->context->addError($this->id, $error);
    }

    public function setRetryConfig(RetryConfig $retryConfig): self
    {
        $this->retryConfig = $retryConfig;
        return $this;
    }

    // ============================================
    // EXPORT - Convert to array for Redis/DB
    // ============================================

    public function toArray(): array
    {
        return [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'id' => $this->id,
            'retryConfig' => $this->retryConfig
        ];
    }

}
