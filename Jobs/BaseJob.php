<?php

namespace App\WorkflowRodoud\Jobs;

use App\WorkflowRodoud\Contracts\JobInterface;
use App\WorkflowRodoud\Attributes\Job;
use App\WorkflowRodoud\WorkflowContext;
use DateTime;
use ReflectionClass;

abstract class BaseJob implements JobInterface
{
    public string $id;
    public WorkflowContext $context;

    public function getName(): string
    {
        $reflection = new ReflectionClass($this);
        $attributes = $reflection->getAttributes(Job::class);

        if (!empty($attributes)) {
            $jobAttribute = $attributes[0]->newInstance();
            return $jobAttribute->name;
        }

        return class_basename($this);
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


}
